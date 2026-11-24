# Spike: learnable Japanese phrases with timestamps from hololive archives

Run on 2026-11-24. 14 Holodex calls (all through `App\Holodex\Client` via `tinker --execute`, key never touched), about 16 yt-dlp metadata/caption requests, no audio or video downloaded.

Test set: 5 recent archives plus 2 from comment search.

| video | talent | type |
|---|---|---|
| `PHR5fkY1t8Q` | Momosuzu Nene | talk / 晩酌 (97 min) |
| `VWHVPoMD27o` | Momosuzu Nene | singing (84 min) |
| `_DAtn-yPnaI` | Hoshimachi Suisei | celebration talk (138 min) |
| `mbFmBqlbyXs` | FUWAWA (EN, "日本語多め") | game (123 min) |
| `mo1gah3pcyw` | Usada Pekora | GTA VI (87 min) |
| `UpKoiW0RNQQ`, `WcT75V1d5a0` | Tsuzuri (channel came from comment search with org=Hololive, check affiliation against `talents`), Anya | from comment search |

Raw data and scripts: `videos.json`, `details.json`, `probe1.json`, `probe2.json`, `subs/*.json3`, `cues.py` (json3 to timestamped lines), `verify.py` (quote vs caption check), all in this folder.

---

## 1a. Holodex timestamp comments (`GET /videos/{id}?c=1`): PARTLY works

- Coverage: 5/5 videos had comments (2 to 15 each).
- Noise: high. Most comments are reactions ("41:08 やめてくれねねち、その話はオレに効く") or topic lists, not quotes. 3/5 videos had a bot-style "多言語ハイライト" list. Those lists are teasers ("00:03:42 ▶ 寝落ちで遅刻したねねの第一声は…"), not speech.
- Gold when present: fan timestamp lists that quote speech. FUWAWA `mbFmBqlbyXs`: `5:38-めつらしの時間ね`, `17:30-雨降ってるとしんどいよね`, `22:02-お水いっぱい欲しいね`, `22:52-冬にも負けない強いタンポポー!`. Pekora `mo1gah3pcyw` has a full chapter list (`00:50:57 無より好き嫌いと思ってもらるほうが`).
- Use: as a *moment pointer* ("something happens at t"), not as text to teach.
- Cost: trivial. One call per video, and the import already exists in `HolodexSync`.

## 1b. Holodex comment search (`POST /search/commentSearch`): WORKS, best phrase source found

- Searching `comment: ["「"]`, `org: ["Hololive"]` returned `total: 67609` comments. The regex `t ... 「quote」` pulls timestamp+quote pairs straight out:
  - `UpKoiW0RNQQ 16:21 「初めて話す先輩が多い」`, `34:12 「みんな妹ってことですか?」`, `2:20:44 「我こそは1位だと思う方いますか?」`
  - `WcT75V1d5a0 2:12:09 「あけましておめでとうございます」`, `31:58 「練習の時は調子良かったのに...😭」`
  - `_GG79pnBfqo (Miko) 2:09:24 「誰やおまえ(タクシー)」`
- Noise: medium. Viewers paraphrase ("こぼ先輩水着いいですね~"), add emoji, write nicknames or katakana jokes. The org filter also let through channels that may not be hololive (熱千めら, カプラ・リブラス), so filter on the `talents` channel ids.
- These quotes come from humans who heard the line, so they are independent of the auto-captions. Their mistakes are paraphrases, not mishearings. That makes them the ideal second source for the verifier (see 2).
- Cost: low. One POST per page of 15 videos. Paginate nightly by `newest` and stop at the last seen video.

## 1c. Holodex clips (`clips` in video detail, `/channels/{id}/clips?lang=ja`): PARTLY works

- Coverage: 4/5 videos had clips (5 to 20). The singing stream had none.
- Clip titles sometimes quote the line in 『』: `『私のステージへようこそ』` (Polka sleep-talking, told by Nene in `PHR5fkY1t8Q`), `『うっすらぴょこら』でAIを出し抜こうとするぺこら`, `ここで働かせてください🍑🥟`.
- Clip objects have **no offset into the source stream**. To get the timestamp you have to locate the quote in the source captions. That works: `私のステージへようこそ` matches the caption `0:13:59 のステージへくそって言ったの` (see 2).
- Noise: high. Most titles are summaries, ranking compilations ("草コメントランキング TOP10") or zh/en.
- Cost: low (already in the video detail call). Use only titles with 『』/「」 quotes.

## 1d. TLdex / live translations (`GET /videos/{id}/chats?lang=ja|en`): NO

- The endpoint exists and has `video_offset`, but `PHR5fkY1t8Q` returned 1 message for `ja` (a viewer correction, `is_tl: true`) and 0 for `en`. TLdex is close to dead for archives.

## 2. YouTube Japanese auto-captions (yt-dlp, `--skip-download --write-auto-subs --sub-langs ja-orig --sub-format json3`): WORKS, noisy text

- Coverage: **7/7** archives have `ja-orig` auto-captions, the EN talent's Japanese stream included. **0/7** have manual subtitles; the only "subtitle" track is `live_chat`.
- Fetch: one request per video, 0.6 to 1.7 MB json3, 833 to 2408 cue lines, word-level timings in ms. No API key, no blocks with the `holo-learn (+https://holo-learn.u0x.de)` UA at a pace of 5 s per request. The installed yt-dlp (2026.08.19) warns that it is old but still works.
- Clean, everyday lines (good learning material):
  - `PHR5fkY1t8Q 1:36:36 というわけで皆さん今日も最後まで配信見てくれてありがとうございました。` / `1:36:43 また次の配信でお会いしましょう。`
  - `mbFmBqlbyXs 1:59:27 今日楽しかったよ。ありがとう。`
  - `mo1gah3pcyw 0:00:35 みんな枠移動ありがとう`
- **Mishearings hit exactly the catchphrases and names we care about most:**
  - Nene's greeting `0:03:31 ホロライブご 規生ねプラボのね、みんなの心の エナゼリンク もすずねです` (should read `ホロライブ5期生ねぽらぼの、みんなの心のエナジーリンク、桃鈴ねねです`). In `VWHVPoMD27o 0:02:00` the same greeting becomes `ホロライブご規制寝ばらボ … エナジードリンクもすずねです`.
  - Pekora `1:25:54 不視聴田ペコーラフェニックスペコーラ`, closing `おつこでした` (= おつぺこでした). Suisei `see ユアゲインハイス … つ待ち` (= バイすい / おつまち). `東京ドム` (ドーム), `乾燥` (完走, twice), `水星` (すいせい).
  - FUWAWA's mixed EN/JA output is mostly garbage (`ゾボロブウイルおし[泣き声]もです`, `Theywon beamtomoryouknow`).
- Other noise: `[鼻息] [笑い] [音楽] [叫び声]` tags, fragments split mid-word across cues, and song lyrics in singing streams (`VWHVPoMD27o 0:21:00 私は誰かのヒロインじゃなくて…`). Lyrics have to be dropped: they are copyrighted and not speech.
- **Cross-check against comment quotes** (`verify.py`: NFKC, katakana to hiragana, best partial `difflib` ratio within ±30 s): **22/35 quotes matched at ≥ 0.75**.
  - Exact matches: `初めて話す先輩が多い`, `あけましておめでとうございます`, `みんな妹ってことですか?`.
  - Misses were roughly half caption errors (`早くドパりたい` heard as `早く伸ばりたい`) and half viewer paraphrase (`「みんなこれが1位の景色だよ」` vs. the caption `みんな来たっぽいこれ`).
  - A few misses are kanji/kana spelling only (`ザコじゃないよ` vs `雑魚じゃないよ`, 0.71). A reading normalizer (kana via the existing dictionary or pykakasi) would turn these into matches.
- Cost: medium. One PHP or Python job, one yt-dlp process per video, about 1 MB per video (parse, then throw the file away). The parser is 10 lines (`cues.py`).
- Legal/ToS: the official Data API `captions.download` only works for the video owner. The only way to get the captions is the unofficial timedtext endpoint used by yt-dlp, which breaks YouTube's ToS on automated access (grey area, low volume). Keep only short quoted lines plus a link to `watch?v=…&t=…`, never full transcripts or lyrics. That fits a quote-style use and Cover's non-commercial fan-work guidelines, but it is not a license.

## 3. Chat density / superchat peaks: FEASIBLE, but blocked with our UA

- yt-dlp lists a `live_chat` track for 3/3 tested archives. Fetching it with the required `holo-learn` UA fails at fragment 2. YouTube returns an HTML page saying "It looks like you're using an older version of your browser. Please update it to use live chat." The latest yt-dlp via `uvx` (2026.11.23) fails the same way.
- It would probably work with a browser UA. I did not try that because the brief fixes the UA. That is a policy decision for you.
- Even unblocked, it costs one continuation request per few seconds of stream, so thousands of requests and several minutes per 2 h archive. The signal (laughter spam `草`/`www`, `助かる`, superchat bursts) only says *when*, not *what was said*.
- The YouTube "most replayed" `heatmap` field was empty for all 3 (`--dump-json`).
- Verdict: not worth it. Viewer timestamp comments already give the "memorable moment" signal at 1/1000 of the cost.

## 4. Better finds

- **Opening and closing windows from captions, verified against a per-talent phrase list.** Every stream has a greeting in the first minutes after `start_actual` and a sign-off in the last minutes before the end. Closings are standard, clean Japanese (`最後まで見てくれてありがとうございました`, `また次の配信でお会いしましょう`, `手を合わせてください。ごちそうさまでした`).
  - A hand-curated table of about 3 catchphrases per talent (こんぺこ/おつぺこ, こんねね, おつまち, BAU BAU …) does two jobs. It finds the timestamp by fuzzy match, because the misheard form is still close: `もすずねです` vs `ももすずねねです`. And the caption text is then replaced by the canonical spelling, so the misheard text is never shown.
- **Comment search + captions = two independent witnesses.** This is the core of the recommendation below.

---

## Candidate phrases (real, from these archives)

`src`: C = auto-caption, Q = viewer quote, T = clip title. `✓` = two sources agree or a clean caption. Fixes in brackets are corrections of mishearings.

| # | Japanese | video | t | src |
|---|---|---|---|---|
| 1 | 皆さん今日も最後まで配信見てくれてありがとうございました。 | `PHR5fkY1t8Q` | 1:36:33 | C ✓ |
| 2 | また次の配信でお会いしましょう。 | `PHR5fkY1t8Q` | 1:36:43 | C ✓ (same in `mo1gah3pcyw` 1:26:15 as また次回の配信で…) |
| 3 | じゃあね。バイバイ。おやすみ。またね。 | `PHR5fkY1t8Q` | 1:36:49 | C ✓ |
| 4 | 皆さん手を合わせてください。ごちそうさまでした。 | `PHR5fkY1t8Q` | 1:36:05 | C (caption: ごちそう様) |
| 5 | 30分だけ寝ようって思ったら寝てた。 | `PHR5fkY1t8Q` | 0:03:56 | C ✓ |
| 6 | 私のステージへようこそ | `PHR5fkY1t8Q` | 0:13:56 | T + C (caption: ステージへくそ) |
| 7 | ついについにツアーが終わった。 | `_DAtn-yPnaI` | 0:09:08 | C ✓ |
| 8 | ちょっとずつ頑張っていきますので | `_DAtn-yPnaI` | 2:16:14 | C ✓ |
| 9 | 東京ドーム目指してみんなで改めて頑張っていこうぜ。 | `_DAtn-yPnaI` | 2:16:17 | C (caption: 東京ドム) |
| 10 | ちょっと珍しい時間帯になりましたけど | `mbFmBqlbyXs` | 0:05:35 | C + fan TS `めつらしの時間ね` |
| 11 | 今日は日本語多めでやりたいなと思ってます。 | `mbFmBqlbyXs` | 0:05:54 | C ✓ |
| 12 | 今日楽しかったよ。ありがとう。 | `mbFmBqlbyXs` | 1:59:27 | C ✓ |
| 13 | 雨降ってるとしんどいよね | `mbFmBqlbyXs` | 0:17:30 | fan TS (caption not checked) |
| 14 | せめてチャプター1までは遊ばせろ | `mo1gah3pcyw` | 0:00:15 | C ✓ + chapter comment |
| 15 | みんな枠移動ありがとう | `mo1gah3pcyw` | 0:00:35 | C ✓ |
| 16 | 今日はお休み前ラストということで歌枠したいと思います。 | `VWHVPoMD27o` | 0:02:11 | C ✓ |
| 17 | 初めて話す先輩が多い | `UpKoiW0RNQQ` | 0:16:21 | Q + C 1.00 |
| 18 | みんな妹ってことですか? | `UpKoiW0RNQQ` | 0:34:12 | Q + C 1.00 |
| 19 | 我こそは1位だと思う方いますか? | `UpKoiW0RNQQ` | 2:20:44 | Q + C 0.93 (caption: 我こそが) |
| 20 | 練習の時は調子良かったのに | `WcT75V1d5a0` | 0:31:58 | Q + C 0.92 |
| 21 | あけましておめでとうございます | `WcT75V1d5a0` | 2:12:09 | Q + C 1.00 |

---

## Recommended pipeline (fits `refs` new → annotated → verified)

1. Nightly `holodex:sync` extension: `POST /search/commentSearch` (`「`/`『`, org Hololive, newest, stop at the last seen video), plus `clips` titles with 『』, kept only for channel ids in `talents`. Regex out `(video_id, t, quote)`.
2. For each such video (budget: N per night), fetch `ja-orig` json3 with yt-dlp `--skip-download`, parse cues, keep only the ±30 s window around each quote and the first/last 5 min. Delete the file.
3. Code check, no LLM: the quote must fuzzy-match the caption window at ≥ 0.75 after NFKC + kana folding. Snap `t` to the matching cue start. Insert the *viewer's* spelling as `refs` (`kind = phrase`, new) plus an evidence row (video, t, caption snippet, score).
4. Greetings/closings: match a curated per-talent catchphrase table against the opening/closing windows. Store the canonical text, never the caption text.
5. Claude subagents annotate `new` refs as today (reading, gloss, items, and fix the spelling to standard kanji/kana), with the caption snippet and quote shown as evidence. A subagent may *reject* but never invent text that is not in the evidence.
6. Pure-code verifier: the annotated `text_ja` (folded to kana via the dictionary) still has to match both the quote and the caption window (≥ 0.75 each), contain no lyrics (skip `topic_id = singing` and `[音楽]` windows), stay ≤ 40 chars, and come from a talent channel. Otherwise → `rejected`.

**Keeping mishearings out:** caption text alone is never taught. A phrase reaches `verified` only if two independent witnesses agree: a human quote (comment or clip title) and the caption, or a curated catchphrase and the caption. ASR and humans fail in different ways (ASR mishears names and slang, humans paraphrase), so agreement above the threshold is strong evidence. The cost is recall: about 37% of quotes drop out. Lines that only the caption has (like #1 to #5) stay `new` until someone confirms them. The verifier is code, so annotation can fix spelling but cannot slip in unverified wording.
