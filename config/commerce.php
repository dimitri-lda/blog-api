<?php

return [
    'default_country' => env('COMMERCE_DEFAULT_COUNTRY', 'DE'),
    'geoip_url' => env('GEOIP_URL', 'https://ipwho.is/{ip}'),
    'delivery' => [
        'standard_net_cents' => (int) env('DELIVERY_STANDARD_NET_CENTS', 590),
        'express_net_cents' => (int) env('DELIVERY_EXPRESS_NET_CENTS', 1290),
        'free_shipping_gross_eur_cents' => (int) env('DELIVERY_FREE_SHIPPING_GROSS_EUR_CENTS', 5000),
    ],
    'markets' => [
        'BY' => ['code' => 'belarus', 'currency' => 'BYN', 'locale' => 'ru', 'vat' => 20.0],
        'PL' => ['code' => 'poland', 'currency' => 'PLN', 'locale' => 'pl', 'vat' => 23.0],
    ],
    // Standard VAT rates for ordinary physical goods. Keep this mapping reviewed
    // against the European Commission's TEDB before changing a rate in production.
    'eu_vat' => [
        'AT' => 20.0, 'BE' => 21.0, 'BG' => 20.0, 'HR' => 25.0, 'CY' => 19.0,
        'CZ' => 21.0, 'DK' => 25.0, 'EE' => 24.0, 'FI' => 25.5, 'FR' => 20.0,
        'DE' => 19.0, 'GR' => 24.0, 'HU' => 27.0, 'IE' => 23.0, 'IT' => 22.0,
        'LV' => 21.0, 'LT' => 21.0, 'LU' => 17.0, 'MT' => 18.0, 'NL' => 21.0,
        'PL' => 23.0, 'PT' => 23.0, 'RO' => 21.0, 'SK' => 23.0, 'SI' => 22.0,
        'ES' => 21.0, 'SE' => 25.0,
    ],
    'country_names' => [
        'BY' => ['en' => 'Belarus', 'ru' => 'Беларусь', 'pl' => 'Białoruś'],
        'PL' => ['en' => 'Poland', 'ru' => 'Польша', 'pl' => 'Polska'],
        'AT' => ['en' => 'Austria', 'ru' => 'Австрия', 'pl' => 'Austria'], 'BE' => ['en' => 'Belgium', 'ru' => 'Бельгия', 'pl' => 'Belgia'],
        'BG' => ['en' => 'Bulgaria', 'ru' => 'Болгария', 'pl' => 'Bułgaria'], 'HR' => ['en' => 'Croatia', 'ru' => 'Хорватия', 'pl' => 'Chorwacja'],
        'CY' => ['en' => 'Cyprus', 'ru' => 'Кипр', 'pl' => 'Cypr'], 'CZ' => ['en' => 'Czechia', 'ru' => 'Чехия', 'pl' => 'Czechy'],
        'DK' => ['en' => 'Denmark', 'ru' => 'Дания', 'pl' => 'Dania'], 'EE' => ['en' => 'Estonia', 'ru' => 'Эстония', 'pl' => 'Estonia'],
        'FI' => ['en' => 'Finland', 'ru' => 'Финляндия', 'pl' => 'Finlandia'], 'FR' => ['en' => 'France', 'ru' => 'Франция', 'pl' => 'Francja'],
        'DE' => ['en' => 'Germany', 'ru' => 'Германия', 'pl' => 'Niemcy'], 'GR' => ['en' => 'Greece', 'ru' => 'Греция', 'pl' => 'Grecja'],
        'HU' => ['en' => 'Hungary', 'ru' => 'Венгрия', 'pl' => 'Węgry'], 'IE' => ['en' => 'Ireland', 'ru' => 'Ирландия', 'pl' => 'Irlandia'],
        'IT' => ['en' => 'Italy', 'ru' => 'Италия', 'pl' => 'Włochy'], 'LV' => ['en' => 'Latvia', 'ru' => 'Латвия', 'pl' => 'Łotwa'],
        'LT' => ['en' => 'Lithuania', 'ru' => 'Литва', 'pl' => 'Litwa'], 'LU' => ['en' => 'Luxembourg', 'ru' => 'Люксембург', 'pl' => 'Luksemburg'],
        'MT' => ['en' => 'Malta', 'ru' => 'Мальта', 'pl' => 'Malta'], 'NL' => ['en' => 'Netherlands', 'ru' => 'Нидерланды', 'pl' => 'Holandia'],
        'PT' => ['en' => 'Portugal', 'ru' => 'Португалия', 'pl' => 'Portugalia'], 'RO' => ['en' => 'Romania', 'ru' => 'Румыния', 'pl' => 'Rumunia'],
        'SK' => ['en' => 'Slovakia', 'ru' => 'Словакия', 'pl' => 'Słowacja'], 'SI' => ['en' => 'Slovenia', 'ru' => 'Словения', 'pl' => 'Słowenia'],
        'ES' => ['en' => 'Spain', 'ru' => 'Испания', 'pl' => 'Hiszpania'], 'SE' => ['en' => 'Sweden', 'ru' => 'Швеция', 'pl' => 'Szwecja'],
    ],
];
