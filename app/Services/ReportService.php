<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Task;
use App\Models\User;
use ZipArchive;

/**
 * Builds datasets (scoped to what the user may see) and renders them in any format:
 * csv, xlsx, docx, pdf, html, md, txt, json. No external packages - xlsx/docx are zipped XML, pdf is written by hand.
 */
class ReportService
{
    public const DATASETS = ['tasks', 'summary', 'activity', 'users', 'categories'];

    public const FORMATS = ['csv', 'xlsx', 'docx', 'pdf', 'html', 'md', 'txt', 'json'];

    public const FORMAT_LABELS = ['csv' => 'CSV', 'xlsx' => 'Excel', 'docx' => 'Word', 'pdf' => 'PDF', 'html' => 'HTML', 'md' => 'Markdown', 'txt' => 'Text', 'json' => 'JSON'];

    private const MIME = [
        'csv' => 'text/csv; charset=UTF-8',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pdf' => 'application/pdf', 'html' => 'text/html; charset=UTF-8', 'md' => 'text/markdown; charset=UTF-8',
        'txt' => 'text/plain; charset=UTF-8', 'json' => 'application/json',
    ];

    public const FILTERS = ['status', 'priority', 'due', 'q', 'assignee', 'category_id', 'tag'];

    /* ------------------------------------------------------------------ access + url */

    public static function allowed(User $user, string $dataset): bool
    {
        return match ($dataset) {
            'tasks', 'summary' => true,
            'activity', 'categories' => $user->isStaff(),
            'users' => $user->isAdmin(),
            default => false,
        };
    }

    public static function url(string $dataset, string $format, array $filters = []): string
    {
        $filters = array_filter(array_intersect_key($filters, array_flip(self::FILTERS)), fn ($v) => $v !== null && $v !== '');

        return route('reports.download', ['dataset' => $dataset, 'format' => $format] + $filters);
    }

    /* ------------------------------------------------------------------ datasets */

    /** @return array{title:string,subtitle:string,columns:string[],rows:array,count:int} */
    public function build(User $user, string $dataset, array $filters = []): array
    {
        abort_unless(in_array($dataset, self::DATASETS, true) && self::allowed($user, $dataset), 403, 'Your role cannot export that data.');

        $stamp = now()->format('d M Y, H:i');

        switch ($dataset) {
            case 'tasks':
                $tasks = app(TaskService::class)->query($user, $filters + ['sort' => 'due'])->limit(2000)->get();
                $rows = $tasks->map(fn (Task $t) => [
                    $t->id, $t->title, $t->status->label(), $t->priority->label(), $t->due_date?->format('Y-m-d') ?? '',
                    $t->is_overdue ? 'Yes' : 'No', $t->category?->name ?? '', $t->assignee?->name ?? '', $t->owner?->name ?? '',
                    $t->tags->pluck('name')->implode(', '), $t->subtasks_done_count.'/'.$t->subtasks_count, $t->created_at->format('Y-m-d'),
                ])->all();

                return $this->pack('Tasks report', $this->describe($filters).' · '.$stamp, ['ID', 'Title', 'Status', 'Priority', 'Due', 'Overdue', 'Category', 'Assignee', 'Owner', 'Tags', 'Checklist', 'Created'], $rows);

            case 'summary':
                $s = app(StatsService::class)->for($user);
                $rows = [
                    ['Total tasks', $s['total']], ['Pending', $s['pending']], ['Completed', $s['completed']], ['Completion rate', $s['rate'].'%'],
                    ['Overdue', $s['overdue']], ['Due today', $s['due_today']],
                ];
                foreach ($s['priority'] as $p) {
                    $rows[] = ['Pending · '.$p['label'], $p['value']];
                }
                foreach ($s['categories'] as $c) {
                    $rows[] = ['Category · '.$c['label'], $c['value']];
                }
                foreach ($s['workload'] as $w) {
                    $rows[] = ['Workload · '.$w->name, $w->pending_count.' pending / '.$w->done_count.' done'];
                }

                return $this->pack('Progress summary', ($user->isStaff() ? 'Whole workspace' : $user->name).' · '.$stamp, ['Measure', 'Value'], $rows);

            case 'activity':
                $rows = ActivityLog::query()->withoutPersonalTasks()->with('user')->latest('id')->limit(500)->get()
                    ->map(fn ($a) => [$a->created_at?->format('Y-m-d H:i'), $a->user?->name ?? 'System', $a->action, $a->description, strtoupper($a->source)])->all();

                return $this->pack('Activity log', 'Latest 500 events · '.$stamp, ['When', 'Who', 'Action', 'Description', 'Source'], $rows);

            case 'users':
                $rows = User::query()->withCount(['tasks', 'assignedTasks'])->orderBy('name')->get()
                    ->map(fn (User $u) => [$u->name, $u->email, $u->role->label(), $u->is_active ? 'Active' : 'Deactivated', $u->tasks_count, $u->assigned_tasks_count, $u->last_login_at?->format('Y-m-d H:i') ?? 'never'])->all();

                return $this->pack('Users & roles', $stamp, ['Name', 'Email', 'Role', 'Status', 'Tasks owned', 'Tasks assigned', 'Last login'], $rows);

            default: // categories
                $rows = Category::withCount('tasks')->orderBy('name')->get()->map(fn ($c) => [$c->name, $c->color, $c->description ?? '', $c->tasks_count])->all();

                return $this->pack('Categories', $stamp, ['Name', 'Colour', 'Description', 'Tasks'], $rows);
        }
    }

    private function pack(string $title, string $subtitle, array $columns, array $rows): array
    {
        return ['title' => $title, 'subtitle' => $subtitle, 'columns' => $columns, 'rows' => $rows, 'count' => count($rows)];
    }

    private function describe(array $f): string
    {
        $bits = [];
        foreach (['status', 'priority', 'due', 'q', 'tag'] as $k) {
            if (! empty($f[$k])) {
                $bits[] = $k.': '.$f[$k];
            }
        }

        return $bits ? implode(', ', $bits) : 'All visible tasks';
    }

    /* ------------------------------------------------------------------ rendering */

    /** @return array{filename:string,mime:string,content:string} */
    public function render(array $data, string $format): array
    {
        abort_unless(in_array($format, self::FORMATS, true), 422, 'Unknown format.');
        $name = trim(preg_replace('/[^a-z0-9]+/i', '-', strtolower($data['title'])), '-').'-'.now()->format('Ymd-Hi').'.'.$format;

        $content = match ($format) {
            'csv' => $this->csv($data),
            'json' => json_encode(['title' => $data['title'], 'subtitle' => $data['subtitle'], 'columns' => $data['columns'], 'rows' => array_map(fn ($r) => array_combine($data['columns'], $r), $data['rows'])], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'md' => $this->markdown($data),
            'txt' => $this->text($data),
            'html' => $this->html($data),
            'xlsx' => $this->xlsx($data),
            'docx' => $this->docx($data),
            'pdf' => $this->pdf($data),
        };

        return ['filename' => $name, 'mime' => self::MIME[$format], 'content' => $content];
    }

    private function csv(array $d): string
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 correctly
        fputcsv($h, $d['columns'], ',', '"', '\\');
        foreach ($d['rows'] as $r) {
            fputcsv($h, array_map(fn ($v) => $this->csvSafe((string) $v), $r), ',', '"', '\\');
        }
        rewind($h);

        return stream_get_contents($h);
    }

    /** Stop spreadsheet formula injection from user-typed text. */
    private function csvSafe(string $v): string
    {
        return preg_match('/^[=+\-@\t\r]/', $v) && ! is_numeric($v) ? "'".$v : $v;
    }

    private function markdown(array $d): string
    {
        $esc = fn ($v) => str_replace(['|', "\n"], ['\|', ' '], (string) $v);
        $o = "# {$d['title']}\n\n_{$d['subtitle']}_\n\n| ".implode(' | ', $d['columns'])." |\n|".str_repeat(' --- |', count($d['columns']))."\n";
        foreach ($d['rows'] as $r) {
            $o .= '| '.implode(' | ', array_map($esc, $r))." |\n";
        }

        return $o."\n{$d['count']} row(s).\n";
    }

    private function text(array $d): string
    {
        $w = array_map('mb_strlen', $d['columns']);
        foreach ($d['rows'] as $r) {
            foreach ($r as $i => $v) {
                $w[$i] = min(40, max($w[$i], mb_strlen((string) $v)));
            }
        }
        $line = fn (array $r) => implode('  ', array_map(fn ($v, $i) => mb_str_pad(mb_strimwidth((string) $v, 0, 40, '…'), $w[$i]), $r, array_keys($r)));
        $o = $d['title']."\n".$d['subtitle']."\n\n".$line($d['columns'])."\n".str_repeat('-', array_sum($w) + 2 * (count($w) - 1))."\n";
        foreach ($d['rows'] as $r) {
            $o .= $line($r)."\n";
        }

        return $o;
    }

    private function html(array $d): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $th = implode('', array_map(fn ($c) => '<th>'.$e($c).'</th>', $d['columns']));
        $tr = implode('', array_map(fn ($r) => '<tr>'.implode('', array_map(fn ($v) => '<td>'.$e($v).'</td>', $r)).'</tr>', $d['rows']));

        return '<!doctype html><html><head><meta charset="utf-8"><title>'.$e($d['title']).'</title><style>body{font-family:Arial,sans-serif;margin:32px;color:#1f2328}h1{margin:0 0 4px}p{color:#5d6670}table{border-collapse:collapse;width:100%;font-size:13px}th{background:#0d1117;color:#fff;text-align:left;padding:8px}td{padding:7px 8px;border-bottom:1px solid #e3e8ee}tr:nth-child(even) td{background:#f6f8fa}</style></head><body><h1>'
            .$e($d['title']).'</h1><p>'.$e($d['subtitle']).'</p><table><thead><tr>'.$th.'</tr></thead><tbody>'.$tr.'</tbody></table><p>'.(int) $d['count'].' row(s)</p></body></html>';
    }

    /* ---- xlsx ---- */
    private function xlsx(array $d): string
    {
        $col = function (int $i): string {
            $s = '';
            for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
                $s = chr(65 + ($i - 1) % 26).$s;
            }

            return $s;
        };
        $cell = function (int $c, int $r, mixed $v, bool $head = false) use ($col): string {
            $ref = $col($c).$r;
            $style = $head ? ' s="1"' : '';
            if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d{1,12}(\.\d+)?$/', $v) && ! preg_match('/^0\d/', $v))) {
                return '<c r="'.$ref.'"'.$style.'><v>'.$v.'</v></c>';
            }

            return '<c r="'.$ref.'" t="inlineStr"'.$style.'><is><t xml:space="preserve">'.$this->xml((string) $v).'</t></is></c>';
        };

        $rowsXml = '<row r="1">'.implode('', array_map(fn ($v, $i) => $cell($i, 1, $v, true), $d['columns'], array_keys($d['columns']))).'</row>';
        foreach ($d['rows'] as $n => $r) {
            $rowsXml .= '<row r="'.($n + 2).'">'.implode('', array_map(fn ($v, $i) => $cell($i, $n + 2, $v), $r, array_keys($r))).'</row>';
        }
        $widths = '';
        foreach ($d['columns'] as $i => $c) {
            $max = mb_strlen($c);
            foreach (array_slice($d['rows'], 0, 200) as $r) {
                $max = max($max, mb_strlen((string) ($r[$i] ?? '')));
            }
            $widths .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.min(60, max(10, $max + 3)).'" customWidth="1"/>';
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>'.$widths.'</cols><sheetData>'.$rowsXml.'</sheetData><autoFilter ref="A1:'.$col(max(0, count($d['columns']) - 1)).max(1, count($d['rows']) + 1).'"/></worksheet>';

        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$this->xml(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $d['title']), 0, 31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF2C7A7B"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ]);
    }

    /* ---- docx ---- */
    private function docx(array $d): string
    {
        $n = max(1, count($d['columns']));
        $w = intdiv(9360 * 1, $n); // landscape-friendly even split of the usable width
        $cell = fn (string $t, bool $head) => '<w:tc><w:tcPr><w:tcW w:w="'.$w.'" w:type="dxa"/>'.($head ? '<w:shd w:val="clear" w:color="auto" w:fill="2C7A7B"/>' : '').'</w:tcPr><w:p><w:r><w:rPr>'.($head ? '<w:b/><w:color w:val="FFFFFF"/>' : '').'<w:sz w:val="17"/></w:rPr><w:t xml:space="preserve">'.$this->xml($t).'</w:t></w:r></w:p></w:tc>';
        $row = fn (array $r, bool $head = false) => '<w:tr>'.($head ? '<w:trPr><w:tblHeader/></w:trPr>' : '').implode('', array_map(fn ($v) => $cell((string) $v, $head), $r)).'</w:tr>';

        $tbl = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders><w:top w:val="single" w:sz="4" w:color="D0D7DE"/><w:bottom w:val="single" w:sz="4" w:color="D0D7DE"/><w:insideH w:val="single" w:sz="4" w:color="E3E8EE"/></w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid>'.str_repeat('<w:gridCol w:w="'.$w.'"/>', $n).'</w:tblGrid>'.$row($d['columns'], true).implode('', array_map($row, $d['rows'])).'</w:tbl>';
        $body = '<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="36"/><w:color w:val="2C7A7B"/></w:rPr><w:t>'.$this->xml($d['title']).'</w:t></w:r></w:p>'
            .'<w:p><w:r><w:rPr><w:color w:val="5D6670"/></w:rPr><w:t xml:space="preserve">'.$this->xml($d['subtitle']).'</w:t></w:r></w:p>'.$tbl
            .'<w:p><w:r><w:rPr><w:color w:val="959EA8"/><w:sz w:val="16"/></w:rPr><w:t>'.(int) $d['count'].' row(s) · generated by '.$this->xml(config('app.name')).'</w:t></w:r></w:p>'
            .'<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/><w:pgMar w:top="900" w:right="900" w:bottom="900" w:left="900" w:header="400" w:footer="400" w:gutter="0"/></w:sectPr>';

        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>',
        ]);
    }

    /* ---- pdf (hand-written, Helvetica, landscape A4, paginated table) ---- */
    private function pdf(array $d): string
    {
        $W = 842.0;
        $H = 595.0;
        $m = 36.0;
        $cols = max(1, count($d['columns']));
        // column widths proportional to content (min/max clamped), scaled to the page
        $len = array_map(fn ($c) => mb_strlen($c), $d['columns']);
        foreach (array_slice($d['rows'], 0, 300) as $r) {
            foreach ($r as $i => $v) {
                $len[$i] = max($len[$i], min(34, mb_strlen((string) $v)));
            }
        }
        $len = array_map(fn ($l) => max(6, min(34, $l)), $len);
        $scale = ($W - 2 * $m) / array_sum($len);
        $cw = array_map(fn ($l) => $l * $scale, $len);
        $fs = $cols > 9 ? 6.5 : ($cols > 6 ? 7.5 : 8.5);
        $rowH = $fs + 7;

        $pages = [];
        $cur = '';
        $y = $H - $m;
        $new = function () use (&$cur, &$y, $H, $m, $d, $fs, $cw, $rowH, &$pages) {
            if ($cur !== '') {
                $pages[] = $cur;
            }
            $cur = '';
            $y = $H - $m;
            if (! $pages) { // title block on page 1
                $cur .= $this->pdfText($m, $y - 14, $d['title'], 17, true, [0.17, 0.48, 0.48]);
                $cur .= $this->pdfText($m, $y - 30, $d['subtitle'], 9, false, [0.4, 0.43, 0.47]);
                $y -= 44;
            }
            // header row
            $cur .= $this->pdfRect($m, $y - $rowH, array_sum($cw), $rowH, [0.05, 0.07, 0.09]);
            $x = $m;
            foreach ($d['columns'] as $i => $c) {
                $cur .= $this->pdfText($x + 3, $y - $rowH + 4, $this->fit($c, $cw[$i], $fs), $fs, true, [1, 1, 1]);
                $x += $cw[$i];
            }
            $y -= $rowH;
        };
        $new();

        foreach ($d['rows'] as $n => $r) {
            if ($y - $rowH < $m + 14) {
                $new();
            }
            if ($n % 2 === 1) {
                $cur .= $this->pdfRect($m, $y - $rowH, array_sum($cw), $rowH, [0.96, 0.97, 0.98]);
            }
            $x = $m;
            foreach ($r as $i => $v) {
                $cur .= $this->pdfText($x + 3, $y - $rowH + 4, $this->fit((string) $v, $cw[$i], $fs), $fs, false, [0.12, 0.14, 0.16]);
                $x += $cw[$i];
            }
            $y -= $rowH;
        }
        $cur .= $this->pdfText($m, $m - 12, $d['count'].' row(s) · '.config('app.name'), 7.5, false, [0.58, 0.62, 0.66]);
        $pages[] = $cur;

        // assemble objects
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $count = count($pages);
        for ($i = 0; $i < $count; $i++) {
            $kids[] = (5 + $i * 2).' 0 R';
        }
        $objs[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$count.' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($pages as $i => $content) {
            $pageNo = 5 + $i * 2;
            $contentNo = $pageNo + 1;
            $footer = $this->pdfText($W - $m - 40, $m - 12, 'Page '.($i + 1).' / '.$count, 7.5, false, [0.58, 0.62, 0.66]);
            $stream = $content.$footer;
            $objs[$pageNo] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$W.' '.$H.'] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$contentNo.' 0 R >>';
            $objs[$contentNo] = '<< /Length '.strlen($stream).' >>'."\nstream\n".$stream."\nendstream";
        }
        ksort($objs);
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $no => $body) {
            $offsets[$no] = strlen($out);
            $out .= $no." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref'."\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }
        $out .= 'trailer << /Size '.(count($objs) + 1).' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF";

        return $out;
    }

    private function pdfText(float $x, float $y, string $t, float $size, bool $bold, array $rgb): string
    {
        return sprintf("BT /%s %.1f Tf %.3f %.3f %.3f rg %.1f %.1f Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $rgb[0], $rgb[1], $rgb[2], $x, $y, $this->pdfEsc($t));
    }

    private function pdfRect(float $x, float $y, float $w, float $h, array $rgb): string
    {
        return sprintf("%.3f %.3f %.3f rg %.1f %.1f %.1f %.1f re f\n", $rgb[0], $rgb[1], $rgb[2], $x, $y, $w, $h);
    }

    private function pdfEsc(string $t): string
    {
        $t = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $t) ?: preg_replace('/[^\x20-\x7E]/', '?', $t);

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $t);
    }

    /** Trim text so it fits a column (Helvetica averages ~0.5em per glyph). */
    private function fit(string $t, float $width, float $fs): string
    {
        $max = max(3, (int) floor(($width - 6) / ($fs * 0.5)));

        return mb_strlen($t) > $max ? mb_substr($t, 0, $max - 1).'…' : $t;
    }

    /* ---- helpers ---- */
    private function xml(string $s): string
    {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s) ?? '';

        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** @param array<string,string> $files */
    private function zip(array $files): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rpt');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bin = file_get_contents($tmp);
        @unlink($tmp);

        return $bin;
    }
}
