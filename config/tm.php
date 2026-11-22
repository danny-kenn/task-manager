<?php

return [
    // approval = people can sign up but a manager/admin must approve them (default)
    // open     = sign-up is instantly active (members only)
    // closed   = no public sign-up; accounts are created by an admin/manager
    'registration' => env('TM_REGISTRATION', 'approval'),
];
