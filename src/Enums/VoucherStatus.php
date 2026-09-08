<?php

namespace Modules\Arca\Enums;

enum VoucherStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
