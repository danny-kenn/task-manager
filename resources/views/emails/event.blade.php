<!DOCTYPE html>
<html><body style="margin:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#1f2328">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">
<table width="520" cellpadding="0" cellspacing="0" style="max-width:520px;background:#fff;border-radius:14px;overflow:hidden;border:1px solid #e3e8ee">
<tr><td style="background:#0d1117;padding:18px 24px;color:#d4a017;font-weight:700;font-size:16px;letter-spacing:.3px">{{ config('app.name') }}</td></tr>
<tr><td style="padding:24px">
<p style="margin:0 0 6px;color:#5d6670;font-size:13px">Hi {{ $user->first_name }},</p>
<h2 style="margin:0 0 10px;font-size:19px">{{ $headline }}</h2>
@if ($body)<p style="margin:0 0 20px;font-size:15px;line-height:1.5">{{ $body }}</p>@endif
@if ($url)<a href="{{ $url }}" style="display:inline-block;background:#2c7a7b;color:#fff;text-decoration:none;padding:11px 20px;border-radius:9px;font-weight:600;font-size:14px">Open in Task Manager</a>@endif
<p style="margin:24px 0 0;color:#959ea8;font-size:12px">You can choose which emails you get under Profile → Notifications.</p>
</td></tr></table></td></tr></table>
</body></html>
