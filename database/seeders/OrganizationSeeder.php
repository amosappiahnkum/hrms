<?php

namespace Database\Seeders;

use App\Models\Config\Setting;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $entries = [
            'company.name' => 'Cashpoint Microfinance Limited',
            'company.abbreviation' => 'Cashpoint',
            'company.tagline' => 'Serving your interest……!!!!!',
            'company.founded' => '2013',
            'company.logo_url' => null,
            'company.about' => 'Cashpoint Microfinance Limited is a private Limited Liability Company incorporated under the Companies Act, 1963 (Act 179) in February 2011.
             The Company received a license from the Bank of Ghana to operate as a Tier II microfinance institution on 30th October, 2013. As a member of the Ghana Association of Microfinance Companies (GAMC),
              the Company operates in the Western Region.
              We provide accessible and affordable financial solutions—including susu savings, personal savings, investments, loans, and doorstep banking services—tailored to individuals and micro and small-scale businesses. ',
            'company.mission' => 'To provide quality and affordable financial services to empower micro and small-scale entrepreneurs, inspire and motivate our employees, and ensure sustainable growth and value for our shareholders.',
            'company.vision' => 'To be the best provider of quality and affordable microfinance services to individuals in our target market.',
            'company.core_values' => [
                ['title' => 'Attentive', 'description' => 'We listen respectfully and respond thoughtfully to the needs of our customers, employees, and partners, ensuring our services remain relevant and impactful.', 'icon' => 'integrity'],
                ['title' => 'Consistent', 'description' => 'We uphold high standards of professionalism, ethics, and service delivery. By acting reliably and responsibly, we maintain trust, protect our reputation, and meet regulatory expectations..', 'icon' => 'excellence'],
                ['title' => 'Committed', 'description' => 'We serve with passion and dedication, striving to deliver first-class microfinance solutions that empower individuals and micro and small-scale businesses.', 'icon' => 'innovation'],
                ['title' => 'Accountable', 'description' => 'We take full responsibility for our actions and decisions, operating with transparency, integrity, and compliance in all that we do.', 'icon' => 'teamwork'],
                ['title' => 'Innovative', 'description' => 'We embrace practical innovation and digital solutions to enhance efficiency, improve the customer experience, and adapt to the evolving financial needs of our market.', 'icon' => 'respect'],
//                ['title' => 'Accountability', 'description' => 'We take responsibility for our actions and are transparent in our operations.', 'icon' => 'accountability'],
            ],
            'company.stats' => [
                ['label' => 'Total Staff', 'value' => '40+'],
                ['label' => 'Departments', 'value' => '9'],
                ['label' => 'Years of Excellence', 'value' => '15+'],
            ],
            'company.contact' => [
                'address' => 'P.O Box MC 788 Takoradi',
                'phone' => '0501-294-480',
                'email' => 'info@cashpoint.com.gh',
                'website' => 'www.cashpoint.com.gh',
            ],
        ];

        foreach ($entries as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                [
                    'value' => $value,
                    'group' => 'company',
                    'is_public' => false,
                ]
            );
        }
    }
}
