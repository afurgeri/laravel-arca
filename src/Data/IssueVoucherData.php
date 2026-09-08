<?php

namespace Modules\Arca\Data;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Modules\Arca\Enums\Currency;
use Modules\Arca\Enums\VoucherConcept;
use Modules\Arca\Enums\VoucherLetter;
use Modules\Arca\Enums\VoucherOperation;

final readonly class IssueVoucherData
{
    /**
     * @param  array<string, mixed>  $customer
     * @param  array<string, mixed>  $amounts
     * @param  list<array<string, mixed>>  $vatRates
     * @param  array<string, mixed>|null  $associatedVoucher
     */
    public function __construct(
        public int $pointOfSale,
        public VoucherLetter $letter,
        public VoucherOperation $operation,
        public VoucherConcept $concept,
        public Currency $currency,
        public float $exchangeRate,
        public array $customer,
        public array $amounts,
        public array $vatRates,
        public ?array $associatedVoucher,
        public string $date,
        public ?string $serviceFrom,
        public ?string $serviceTo,
        public ?string $paymentDue,
        public ?string $idempotencyKey,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $validated = Validator::make($input, [
            'point_of_sale' => ['required', 'integer', 'min:1'],
            'letter' => ['required', 'string', 'in:A,B,C'],
            'operation' => ['required', 'string', 'in:invoice,credit_note,debit_note'],
            'concept' => ['nullable', 'string', 'in:products,services,products_and_services'],
            'currency' => ['nullable', 'string', 'in:ARS,USD'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'customer' => ['required', 'array'],
            'customer.document_type' => ['required', 'string'],
            'customer.document_number' => ['required'],
            'customer.vat_condition' => ['required', 'string'],
            'amounts' => ['required', 'array'],
            'amounts.total' => ['required', 'numeric', 'min:0'],
            'amounts.taxable_net' => ['required', 'numeric', 'min:0'],
            'amounts.vat' => ['required', 'numeric', 'min:0'],
            'amounts.exempt' => ['required', 'numeric', 'min:0'],
            'amounts.non_taxed' => ['required', 'numeric', 'min:0'],
            'amounts.other_taxes' => ['required', 'numeric', 'min:0'],
            'vat_rates' => ['nullable', 'array'],
            'vat_rates.*.rate' => ['required', 'numeric', 'min:0'],
            'vat_rates.*.taxable_base' => ['required', 'numeric', 'min:0'],
            'vat_rates.*.amount' => ['required', 'numeric', 'min:0'],
            'associated_voucher' => ['nullable', 'array'],
            'associated_voucher.point_of_sale' => ['required_with:associated_voucher', 'integer', 'min:1'],
            'associated_voucher.number' => ['required_with:associated_voucher', 'integer', 'min:1'],
            'associated_voucher.letter' => ['required_with:associated_voucher', 'string', 'in:A,B,C'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'service_from' => ['nullable', 'date_format:Y-m-d'],
            'service_to' => ['nullable', 'date_format:Y-m-d'],
            'payment_due' => ['nullable', 'date_format:Y-m-d'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ])->after(function ($validator) use ($input): void {
            $amounts = Arr::get($input, 'amounts', []);
            $total = round((float) ($amounts['total'] ?? 0), 2);
            $sum = round(
                (float) ($amounts['taxable_net'] ?? 0)
                + (float) ($amounts['vat'] ?? 0)
                + (float) ($amounts['exempt'] ?? 0)
                + (float) ($amounts['non_taxed'] ?? 0)
                + (float) ($amounts['other_taxes'] ?? 0),
                2,
            );

            if ($total !== $sum) {
                $validator->errors()->add('amounts.total', 'The total must equal the sum of all amount categories.');
            }

            if (($input['operation'] ?? null) !== 'invoice' && ! isset($input['associated_voucher'])) {
                $validator->errors()->add('associated_voucher', 'Credit and debit notes require an associated invoice.');
            }

            if (($input['currency'] ?? 'ARS') === 'USD' && ! isset($input['exchange_rate'])) {
                $validator->errors()->add('exchange_rate', 'An exchange rate is required for USD vouchers.');
            }
        })->validate();

        $currency = Currency::from($validated['currency'] ?? Currency::Ars->value);

        return new self(
            pointOfSale: (int) $validated['point_of_sale'],
            letter: VoucherLetter::from($validated['letter']),
            operation: VoucherOperation::from($validated['operation']),
            concept: VoucherConcept::from($validated['concept'] ?? VoucherConcept::Products->value),
            currency: $currency,
            exchangeRate: $currency === Currency::Usd ? (float) $validated['exchange_rate'] : 1,
            customer: $validated['customer'],
            amounts: $validated['amounts'],
            vatRates: $validated['vat_rates'] ?? [],
            associatedVoucher: $validated['associated_voucher'] ?? null,
            date: $validated['date'] ?? now()->format('Y-m-d'),
            serviceFrom: $validated['service_from'] ?? null,
            serviceTo: $validated['service_to'] ?? null,
            paymentDue: $validated['payment_due'] ?? null,
            idempotencyKey: $validated['idempotency_key'] ?? null,
        );
    }
}
