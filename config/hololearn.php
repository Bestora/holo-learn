<?php

return [

    'holodex' => [
        'base_url' => 'https://holodex.net/api/v2',
        'key' => env('HOLODEX_API_KEY'),
        'user_agent' => 'holo-learn (+https://holo-learn.u0x.de)',
        // Pause after every request; tests set it to 0.
        'pause_ms' => 1000,
    ],

    // Song sync writes "<done> <total> Songs" here while it runs (status-line progress bar); unset = off.
    'progress_file' => env('HOLO_PROGRESS_FILE'),

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

    // Japanese name for channels whose name carries none: a string (alt_text = channel's english_name)
    // or a list of ['ja' => …, 'en' => …] for channels shared by several talents.
    // EN/ID names: official Japanese talent listing hololive.hololivepro.com/talents (2026-11-23).
    // IRyS, AZKi and Civia are Latin-only officially and get none.
    'name_overrides' => [
        // JP
        'UC5CwaMl1eIgY8h02uZw7u8A' => '星街すいせい', // Hoshimachi Suisei ("Suisei Channel")
        'UCFTLzh12_nrtzqBPsTCqenA' => 'アキ・ローゼンタール', // Aki Rosenthal ("アキロゼCh。Vtuber/ホロライブ所属")
        'UCKMWFR6lAstLa7Vbf5dH7ig' => '輪堂千速', // Rindo Chihaya ("Chihaya Ch. 輪堂 千速 - FLOW GLOW")
        'UCDqI2jOz0weumE8s7paEk6g' => 'ロボ子さん', // Robocosan ("Roboco Ch. - ロボ子")

        // EN Myth
        'UCL_qhgtOy0dy1Agp8vkySQg' => '森カリオペ', // Mori Calliope
        'UCHsx4Hqa-1ORjQTh9TYDhww' => '小鳥遊キアラ', // Takanashi Kiara
        'UCMwGHR0BTZuLsmjY_NT5Pwg' => '一伊那尓栖', // Ninomae Ina'nis
        'UCoSrY_IQQVpmIRZ9Xf-y93g' => 'がうる・ぐら', // Gawr Gura
        'UCyl1z3jo3XHR1riLFKG5UAg' => 'ワトソン・アメリア', // Watson Amelia

        // EN Promise
        'UCgmPnx-EEeOrZSg5Tiw7ZRQ' => 'ハコス・ベールズ', // Hakos Baelz
        'UCmbs8T6MWqUHP1tIQvSgKrg' => 'オーロ・クロニー', // Ouro Kronii
        'UC3n5uGu18FoCy23ggWWp8tA' => '七詩ムメイ', // Nanashi Mumei
        'UCO_aKKYxn4tvrqPjcTzZ6EQ' => 'セレス・ファウナ', // Ceres Fauna
        'UCsUj0dszADCGbF3gNrQEuSQ' => '九十九佐命', // Tsukumo Sana

        // EN Advent
        'UC9p_lqQ0FEDz327Vgf5JwqA' => '古石ビジュー', // Koseki Bijou
        'UCgnfPPb9JI3e9A4cXHnWbyg' => 'シオリ・ノヴェラ', // Shiori Novella
        'UC_sFNM0z0MWm9A6WlKPuMMg' => 'ネリッサ・レイヴンクロフト', // Nerissa Ravencroft
        'UCt9H_RpQzhxzlyBxFqrdHqA' => [ // Fuwawa & Mococo Abyssgard
            ['ja' => 'フワワ・アビスガード', 'en' => 'Fuwawa Abyssgard'],
            ['ja' => 'モココ・アビスガード', 'en' => 'Mococo Abyssgard'],
        ],

        // EN Justice
        'UCDHABijvPBnJm7F-KlNME3w' => 'ジジ・ムリン', // Gigi Murin
        'UCl69AEx4MdqMZH7Jtsm7Tig' => 'ラオーラ・パンテーラ', // Raora Panthera
        'UCvN5h1ShZtc7nly3pezRayg' => 'セシリア・イマーグリーン', // Cecilia Immergreen
        'UCW5uhrG1eCBYditmhL0Ykjw' => 'エリザベス・ローズ・ブラッドフレイム', // Elizabeth Rose Bloodflame

        // ID 1st Gen (AREA 15)
        'UCOyYb1c43VlX9rc_lT6NKQw' => 'アユンダ・リス', // Ayunda Risu
        'UCP0BspO_AMEe3aQqqpo89Dg' => 'ムーナ・ホシノヴァ', // Moona Hoshinova
        'UCAoy6rzhSf4ydcYjJw3WoVg' => 'アイラニ・イオフィフティーン', // Airani Iofifteen

        // ID 2nd Gen (holoro)
        'UCYz_5n-uDuChHtLo7My1HnQ' => 'クレイジー・オリー', // Kureiji Ollie
        'UC727SQYUvx5pDDGQpTICNWg' => 'アーニャ・メルフィッサ', // Anya Melfissa
        'UChgTyjG-pdNvxxhdsXfHQ5Q' => 'パヴォリア・レイネ', // Pavolia Reine

        // ID 3rd Gen (holoh3ro)
        'UCTvHWSfBZgtxE4sILOaurIQ' => 'ベスティア・ゼータ', // Vestia Zeta
        'UCZLZ8Jjx_RN2CXloOmgTHVg' => 'カエラ・コヴァルスキア', // Kaela Kovalskia
        'UCjLEmnpCNeisMxy134KPwWw' => 'こぼ・かなえる', // Kobo Kanaeru
    ],

    'min_references' => 3,

    'new_items_per_day' => 10,

    'intervals_hours' => [4, 8, 24, 48, 168, 336, 720, 2880],

    // cards per lesson
    'lesson_size' => 10,

    // a special (bonus) item is at most every n-th new item
    'special_every' => 5,

    // day boundaries for the daily new-item limit and the streak
    'timezone' => 'Europe/Berlin',

];
