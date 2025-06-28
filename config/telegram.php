<?php
$telegramConfig = [
    'amezpricebot_token' => '7687667290:AAFdOS9Q7YbpXRmMHFkJiyt2fs2nR-I1P3E',
    'hotdealsbot_token' => '7582285705:AAEQwjAGFxMQHVdqxQPxi9fsnLaFB6SuSXo',
    'channels' => [
        'amezprice' => '@AmezPrice',
        'hotdeals' => '@AmezPriceHotDeals',
        'updates' => '@AmezPriceUpdates'
    ],
    'buttons' => [
        'amezprice' => [
            ['text' => 'Today’s Deals', 'url' => 'https://t.me/AmezPrice', 'enabled' => true],
            ['text' => 'Bot Updates', 'url' => 'https://t.me/AmezPriceUpdates', 'enabled' => true]
        ],
        'hotdeals' => [
            ['text' => 'Today’s Deals', 'url' => 'https://t.me/AmezPriceHotDeals', 'enabled' => true],
            ['text' => 'Bot Updates', 'url' => 'https://t.me/AmezPriceUpdates', 'enabled' => true]
        ]
    ],'tracking_buttons' => [
        'amezprice' => [
            ['text' => 'Today’s Deals', 'url' => 'https://t.me/AmezPrice', 'enabled' => true],
        ]
    ],
    'api_key' => 'VHAhUdg9JYFZsol1Hn0wrTWJsZVmilZZ'
];
?>