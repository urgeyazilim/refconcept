<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Payments\Models\PaymentBankAccount;
use Illuminate\Database\Seeder;

/**
 * A receiving account so bank transfer works out of the box.
 *
 * The IBAN is a structurally valid test number, not a real one — the checkout page prints
 * it in full for customers to copy, so seeding somebody's actual account would send real
 * money to a stranger. Replaced in production through the finance screen.
 */
final class BankAccountSeeder extends Seeder
{
    public function run(): void
    {
        PaymentBankAccount::query()->updateOrCreate(
            ['iban' => 'TR330006100519786457841326'],
            [
                'bank_name' => 'RefConcept Test Bankası',
                'branch' => 'Merkez',
                'account_holder' => 'RefConcept Teknoloji A.Ş.',
                'currency' => 'TRY',
                'note' => 'Açıklama alanına yalnızca referans kodunu yazın. Başka bir şey yazmayın.',
                'is_active' => true,
                'position' => 0,
            ],
        );
    }
}
