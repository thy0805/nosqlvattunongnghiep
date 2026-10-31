<?php

return [
    'mailer'=>env('SHOP_RESET_MAILER'),
    'from'=>env('SHOP_RESET_FROM'),
    'url'=>env('SHOP_RESET_URL','http://nosql.localhost:3310/shop/reset-password'),
];
