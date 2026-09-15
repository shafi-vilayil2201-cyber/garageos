<?php

// A starting point for the "Find a car" suggestions on vehicle intake —
// common Indian passenger vehicle makes/models, not a precise
// year-range/variant catalog (we have no licensed source for that level
// of detail). Real coverage comes from combining this with the
// organization's own vehicle history — see api/vehicle-models/search.php.
const VEHICLE_CATALOG = [
    'Maruti Suzuki' => ['Swift', 'Baleno', 'WagonR', 'Alto', 'Dzire', 'Ertiga', 'Brezza', 'Celerio', 'Eeco', 'S-Presso', 'Ignis', 'XL6', 'Fronx', 'Jimny'],
    'Hyundai' => ['i10', 'i20', 'Creta', 'Venue', 'Verna', 'Aura', 'Santro', 'Exter', 'Alcazar', 'Tucson'],
    'Tata' => ['Nexon', 'Tiago', 'Tigor', 'Altroz', 'Punch', 'Harrier', 'Safari', 'Indica', 'Indigo'],
    'Mahindra' => ['Scorpio', 'XUV700', 'XUV300', 'Bolero', 'Thar', 'Marazzo', 'KUV100', 'XUV400'],
    'Honda' => ['City', 'Amaze', 'Jazz', 'WR-V', 'Civic', 'Elevate'],
    'Toyota' => ['Innova', 'Fortuner', 'Glanza', 'Urban Cruiser', 'Etios', 'Camry', 'Hyryder'],
    'Kia' => ['Seltos', 'Sonet', 'Carens', 'Carnival'],
    'Renault' => ['Kwid', 'Triber', 'Duster', 'Kiger'],
    'Nissan' => ['Magnite', 'Micra', 'Sunny', 'Terrano'],
    'Ford' => ['EcoSport', 'Figo', 'Aspire', 'Endeavour'],
    'Volkswagen' => ['Polo', 'Vento', 'Taigun', 'Virtus'],
    'Skoda' => ['Rapid', 'Octavia', 'Kushaq', 'Slavia'],
    'MG' => ['Hector', 'Astor', 'ZS EV', 'Comet'],
    'Chevrolet' => ['Beat', 'Spark', 'Cruze', 'Sail'],
    'Datsun' => ['Redi-GO', 'GO', 'GO+'],
    'Jeep' => ['Compass', 'Meridian'],
    'Volvo' => ['XC40', 'XC60', 'S90'],
    'BMW' => ['3 Series', '5 Series', 'X1', 'X3'],
    'Mercedes-Benz' => ['C-Class', 'E-Class', 'GLA', 'GLC'],
    'Audi' => ['A4', 'A6', 'Q3', 'Q5'],
    'Hero MotoCorp' => ['Splendor', 'Passion', 'HF Deluxe', 'Glamour', 'Xtreme'],
    'Honda Motorcycle' => ['Activa', 'Shine', 'Unicorn', 'SP125'],
    'Bajaj' => ['Pulsar', 'Platina', 'CT100', 'Avenger', 'Dominar'],
    'TVS' => ['Apache', 'Jupiter', 'Ntorq', 'Sport'],
    'Royal Enfield' => ['Classic 350', 'Bullet', 'Himalayan', 'Meteor 350'],
    'Yamaha' => ['FZ', 'R15', 'Fascino', 'MT-15'],
];


function vehicle_catalog_search(string $query): array
{
    $query = trim($query);

    if ($query === '') {
        return [];
    }

    $needle = strtolower($query);
    $matches = [];

    foreach (VEHICLE_CATALOG as $make => $models) {
        foreach ($models as $model) {

            $haystack = strtolower($make . ' ' . $model);

            if (str_contains($haystack, $needle)) {
                $matches[] = ['make' => $make, 'model' => $model];
            }
        }
    }

    return $matches;
}
