<?php

return [

    'holodex' => [
        'base_url' => 'https://holodex.net/api/v2',
        'key' => env('HOLODEX_API_KEY'),
        'user_agent' => 'holo-learn (+https://holo-learn.u0x.de)',
        // Pause after every request; tests set it to 0.
        'pause_ms' => 1000,
    ],

    // Imported and annotated first; `talents.priority` = position (1-based).
    'priority_channels' => [
        'UCt9H_RpQzhxzlyBxFqrdHqA', // Fuwawa & Mococo Abyssgard
        'UCL_qhgtOy0dy1Agp8vkySQg', // Mori Calliope
        'UC8rcEBzJSleTkf_-agPM20g', // IRyS
        'UCgmPnx-EEeOrZSg5Tiw7ZRQ', // Hakos Baelz
        'UCHsx4Hqa-1ORjQTh9TYDhww', // Takanashi Kiara
        'UCYz_5n-uDuChHtLo7My1HnQ', // Kureiji Ollie
        'UC1opHUrw8rvnsadT-iGp7Cg', // Minato Aqua (inaktiv)
        'UC5CwaMl1eIgY8h02uZw7u8A', // Hoshimachi Suisei
        'UCIBY1ollUsauvVi4hW4cumw', // Sakamata Chloe (inaktiv)
        'UC1uv2Oq6kNxgATlCiez59hw', // Tokoyami Towa
        'UCdXAk5MpyLD8594lm_OvtGQ', // Juufuutei Raden
        'UC8eitCE9Z6EwUCs-VUi1blg', // Achichi Mela
        'UCqm3BQLlJfvkTsX_hvm0UmA', // Tsunomaki Watame
        'UCAWSyEs_Io8MtpY3m-zqILA', // Momosuzu Nene
        'UCENwRMx5Yh42zWpzURebzTw', // La+ Darknesss
        'UC_vMYWcDjmfdpH6r4TTn1MQ', // Kazama Iroha
        'UCROQtXcp2loQEmvpe5rhJzQ', // Sorashina Sopia
        'UCS9uQI-jC3DE0L4IpXyvr6w', // Kiryu Coco (inaktiv)
        'UCMwGHR0BTZuLsmjY_NT5Pwg', // Ninomae Ina'nis
        'UCmbs8T6MWqUHP1tIQvSgKrg', // Ouro Kronii
        'UC1CfXB_kRs3C-zaeTG3oGyg', // Akai Haato
    ],

    // Japanese name for channels whose name carries none.
    'name_overrides' => [
        'UC5CwaMl1eIgY8h02uZw7u8A' => '星街すいせい', // Hoshimachi Suisei ("Suisei Channel")
        'UCFTLzh12_nrtzqBPsTCqenA' => 'アキ・ローゼンタール', // Aki Rosenthal ("アキロゼCh。Vtuber/ホロライブ所属")
        'UCKMWFR6lAstLa7Vbf5dH7ig' => '輪堂千速', // Rindo Chihaya ("Chihaya Ch. 輪堂 千速 - FLOW GLOW")
        'UCDqI2jOz0weumE8s7paEk6g' => 'ロボ子さん', // Robocosan ("Roboco Ch. - ロボ子")
    ],

    'min_references' => 3,

    'new_items_per_day' => 10,

    'intervals_hours' => [4, 8, 24, 48, 168, 336, 720, 2880],

];
