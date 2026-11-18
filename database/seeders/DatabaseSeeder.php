<?php

namespace Database\Seeders;

use App\Models\Destination;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT SEED DATA ONLY. Names, provinces and districts are factual; everything else is left
 * empty so nothing unverified (prices, road conditions, services) reaches the UI.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $note = 'Development seed record. Route, service and cost information has not been verified yet.';
        $rows = [
            ['pokhara', 'Pokhara', 'Gandaki', 'Kaski', '/images/photos/pokhara-machhapuchhre', 'Machhapuchhre peak above Pokhara with a paraglider in the sky', 'Sagar Dani, CC0'],
            ['bandipur', 'Bandipur', 'Gandaki', 'Tanahun', '/images/photos/bandipur-bazaar', 'The stone-paved main street of Bandipur bazaar lined with brick houses', 'Maesi64, CC0'],
            ['mustang', 'Mustang', 'Gandaki', 'Mustang', null, null, null],
            ['rara', 'Rara', 'Karnali', 'Mugu', '/images/photos/rara-lake', 'Rara Lake surrounded by forested hills', 'scvishnu7 and phuchche, CC0'],
        ];
        foreach ($rows as [$slug, $name, $province, $district, $photo, $alt, $credit]) {
            Destination::firstOrCreate(['slug' => $slug], [
                'name' => $name, 'province' => $province, 'district' => $district, 'summary' => $note,
                'photo_path' => $photo, 'photo_alt' => $alt, 'photo_credit' => $credit,
                'status' => 'published', 'is_dev_seed' => true,
            ]);
        }
    }
}
