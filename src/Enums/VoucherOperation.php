<?php

namespace Modules\Arca\Enums;

enum VoucherOperation: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';
}
