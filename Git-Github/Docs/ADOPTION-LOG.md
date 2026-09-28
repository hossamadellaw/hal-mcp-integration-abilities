# ADOPTION-LOG — تبني نظام GitHub Releases لإضافة HAL MCP Integration Abilities

سجل التبني الخاص بهذه الإضافة حصرًا. لا تُنسخ إليه نتائج أو أدلة من مشاريع أخرى.
كل بند يحمل حالة من ثلاث حالات، و«منشأ الإثبات» وفق قاعدة: «مُثبَت بأداة» أو «إقرار مالك — خارج نطاق الفحص»؛ المعلومة المفقودة تُكتب «غير مسجّل» ولا تُستنتج.

| الحالة | المعنى |
|---|---|
| مخطط / لم ينفذ | قرار مكتوب، لا تنفيذ ولا دليل بعد |
| منفذ محليًا | كود/ملف قائم مع فحص محلي موثق بأداة متاحة — لا يثبت تشغيلًا |
| متحقق تشغيليًا | دليل من بيئة تشغيل فعلية (GitHub/موقع) بتاريخ وأداة |

## هوية التوزيع

| الحقل | القيمة |
|---|---|
| repository / Update URI | https://github.com/hossamadellaw/hal-mcp-integration-abilities |
| slug / مجلد ZIP الأعلى | hal-mcp-integration-abilities |
| main file | hal-mcp-integration-abilities.php |
| bootstrap الداخلي | hal-mcp-abilities/hal-mcp-abilities.php |
| الإصدار الأول المستهدف | 2.0.0 — وسم v2.0.0 |
| أصل التوزيع / التجزئة | hal-mcp-integration-abilities-2.0.0.zip / .sha256 |
| PUC | yahnis-elsts/plugin-update-checker ~5.7.0 — namespace v5p7 — المحلول: v5.7 |
| حدود التشغيل | WordPress ≥ 6.9 — PHP ≥ 8.0 (platform 8.0.0) |
| الوثيقة المعتمدة | Git-Github/Docs/GITHUB-RELEASES-UPDATE-SYSTEM.md |

## سجل البنود

| # | البند | الحالة | الدليل | منشأ الإثبات |
|---|---|---|---|---|
| 1 | composer.json (G04): اعتماد PUC فقط، قيد ~5.7.0، platform.php 8.0.0، بلا scripts/plugins | منفذ محليًا | `composer validate --strict` → «./composer.json is valid»؛ `composer audit --locked --no-dev` → «No security vulnerability advisories found» (2026-09-25، Composer 2.8.12) | مُثبَت بأداة |
| 2 | composer.lock + vendor/ (G05): قفل محلول والتزام اللقطة | منفذ محليًا | Lock يحسم yahnis-elsts/plugin-update-checker v5.7؛ vendor/ مبني محليًا من القفل (composer update --no-dev). الالتزام في Git ينتظر إنشاء المستودع | مُثبَت بأداة |
| 3 | includes/updater.php (G03): ربط PUC بnamespace v5p7، REQUIRE_RELEASE_ASSETS مع ASSET_REGEX حصري، احتواء Throwable، رسالة إدارية منقحة، لا توكن على الموقع العام | منفذ محليًا | `php -l` نظيف؛ حالات محلية في tests/local/run.php: noop بلا ثابت الملف، غياب المكتبة لا يفشل، تهيئة ناجحة عبر مكتبة stub تستقبل الـregex والنمط، فشل مُحتوى بلا تسرب نص الاستثناء | مُثبَت بأداة (محاكاة محلية) |
| 4 | readme.txt (G06): Stable tag = الترويسة = الثابت = الوسم، متطلبات فعلية، Changelog 2.0.0 | منفذ محليًا | Stable tag: 2.0.0 مطابق للترويسة والثابت؛ الأنماط الثلاثة يقرأها build-release.php داخل الحزمة ويطابقها | مُثبَت بأداة |
| 5 | LICENSE (G07): GPL-2.0-or-later | منفذ محليًا — **مُقر نهائيًا من المالك (2026-09-28)** | نص GNU GPL-2.0 الرسمي (338 سطرًا) من gnu.org/licenses/gpl-2.0.txt | إقرار مالك — 2026-09-28 («الترخيص يكون GPL-2.0-or-later» بنص صريح في جولة التفويض) |
| 6 | SECURITY.md (G08): قناة إبلاغ خاصة، لا ادعاء تفعيل private reporting، سياسة أسرار | منفذ محليًا | الملف موجود؛ تفعيل Private vulnerability reporting بقاءً أفعال مالك في إعدادات المستودع | إقرار مالك عند التبني |
| 7 | .gitignore (G09): vendor/ وdist/ والمؤقت والأسرار؛ composer.lock وfixtures غير متجاهلة | منفذ محليًا | الملف موجود؛ لا قاعدة تتجاهل composer.lock أو tests/local/fixtures | مُثبَت بأداة (فحص بصري) |
| 8 | .github/workflows/release.yml (G10): وسم vX.Y.Z، وسم مُعلَّق واحتواء في main، مطابقة الثلاثي وUpdate URI، validate/audit مقفول، مهمتين وصلاحية كتابة للنشر فقط، مسودة ثم نشر، رفض استبدال Release | منفذ محليًا (صياغة) | فحص بنية YAML سليم (PyYAML safe_load). بعد تدقيق المالك 2026-09-25: أضيف `GH_REPO: ${{ github.repository }}` لخطوة النشر (كانت gh عاجزة عن حل المستودع بلا checkout — P1 مُصلح)، وactions الثلاثة مثبتة بـSHA كامل محلول من API رسمي مع تعليق # v4. تشغيله الفعلي على GitHub لم ينفذ — إجراء نشر يحتاج تفويضًا | مُثبَت بأداة للصياغة؛ التشغيل: غير مسجّل |
| 9 | scripts/build-release.php (G11): allowlist، اشتقاق closure، مجلد أعلى واحد، CRC، SHA256 sidecar، هوية من داخل الحزمة، رفض تسرب | منفذ محليًا | بناء فعلي: 165 مدخلة، 21 مرجع closure محقق، sha256 ca6ac75afb0fc29dc9639aa97eb104668faf43cbc7fa6736477de9d68b983729 (دفعة 7)؛ البصمة الحالية بعد إعادة البناء لتضمين رقعة camelCase-URL الأمنية (2026-09-28): sha256 ab29dc943d80b1d03018f4d345ebe1cc814abb56e6f2106c901e8f2473ff1352؛ verify → EXIT 0؛ رفض سلبي: إصدار خاطئ / docs/ / composer.json / .env / ظل بحالة أحرف → EXIT 1. بعد تدقيق المالك: سماحة شكل صارمة (رفض أي مدخلة خارج الشكل المشتق)، سلامة vendor مقابل composer.lock (installed.php pretty+expanded + sanity محمل PUC)، وحدات foreach الست ضمن المطلوب، اشتقاق closure موسع (double-quote/__DIR__/dirname(__DIR__)/bare-string) مع رفض ../ — الحزم العدائية المعروفة كلها تُرفض برسائل مسماة (بطارية الفاحصين + المتحقق الثالث) | مُثبَت بأداة |
| 10 | Git-Github/Docs/GITHUB-RELEASES-UPDATE-SYSTEM.md (G01): النسخة المكيفة بهوية هذا المشروع واستثناء الإغلاق المحلي | منفذ محليًا | الملف قائم؛ بلا نسخ من verifier الخارجي أو ملحقات الجهاز أو سجلات مشاريع أخرى | مراجعة محلية |
| 11 | إنشاء المستودع (فارغ تمامًا) + حمايات main ووسوم v* + 2FA | متحقق تشغيليًا | المستودع عام وفارغ (size: 0، صفر فروع، default branch: main، غير متفرع) — قراءة GitHub REST API بتاريخ 2026-09-28. قاعدة الفرع `main-protection` (id ‏24117760، branch، active): ‏~DEFAULT_BRANCH، approvals ‏0، non_fast_forward + deletion، dismiss/code-owner/last-push/conversations كلها false، merge methods الثلاثة، `bypass_actors: []` و`current_user_can_bypass: "never"`، `require_extra_approval_for_unattributed_changes: true` (مفعّل افتراضيًا من GitHub — عديم الأثر مع approvals ‏0) — مطابقة حرفية للوحة المتفق عليها. قاعدة الوسوم `release-tags-protection` (id ‏24118306، tag، active): ‏refs/tags/v*، non_fast_forward + deletion، bypass فارغ. إثبات الرفض الفعلي لأول دفع مباشر ونجاح أول PR يُنفذ ويُسجل عند البند 12 وفق التصميم | مُثبَت بأداة (gh api ‏2.98.0 بحساب المالك المصادق hossamadellaw)؛ 2FA: إقرار مالك — 2026-09-28 («مفعّلة» بنص صريح في جولة التفويض) |
| 12 | أول push لـmain + وسم v2.0.0 مُعلَّق | مخطط / لم ينفذ | غير مسجّل — إجراء نشر يحتاج تفويضًا | — |
| 13 | نجاح workflow الإصدار على الوسم | مخطط / لم ينفذ | غير مسجّل — يُسجل run URL وrun_attempt وconclusion عند التحقق | — |
| 14 | Release منشور: ZIP + .sha256 بالاسم الصحيح وassets[].digest | مخطط / لم ينفذ | غير مسجّل | — |
| 15 | فحص §6 على الحزمة المنشورة الحقيقية + SHA256 مستقل | مخطط / لم ينفذ | غير مسجّل — الحزمة المحلية ليست بديلًا عن الأصل المنشور | — |
| 16 | تثبيت من حزمة Release على الموقع + عرض التحديث عبر «تحقق مجددًا» | مخطط / لم ينفذ | غير مسجّل — لا موقع حي ولا staging ضمن نطاق الإغلاق المحلي | — |
| 17 | تحديث فعلي عبر مسار ووردبريس + تحقق ما بعده (الإصدار/الوظيفة/debug.log) | مخطط / لم ينفذ | غير مسجّل | — |

## حدود هذا السجل

بنود 1–10 تنفيذ محلي بتاريخ 2026-09-25 (دفعة 7 من خارطة v2.0.0). لا بند هنا يصبح «متحقق تشغيليًا» إلا بدليل تشغيلي مؤرخ من بيئة فعلية. تعديل أي قيمة هوية مستقبلًا يتطلب تحديث updater.php وrelease.yml وbuild-release.php وreadme.txt معًا ثم إعادة فحوص البناء المحلية قبل أي وسم جديد.

**حدود موثقة من المراجعة المستقلة (2026-09-25، قبول بلا P1/P2):**

- `Tested up to: 6.9` في readme.txt يساوي الحد الأدنى المطلوب المعلن فقط، وليس ادعاء اختبار حي على 6.9 — رفعه يحتاج اختبارًا موثقًا (بنود 16–17 أعلاه غير منفذة).
- فحص closure الثابت يتحقق من المراجع النصية الحرفية؛ المراجع المركبة ديناميكيًا (حلقة foreach على includes في bootstrap، ودمج base الأصول في admin.php) تُغطى بفحص «بادئة غير فارغة» + مجموعة الوحدات الست ضمن المطلوب (بعد تدقيق المالك) — وعائلات الصياغة المشمولة الآن: single-quote/double-quote و`__DIR__` و`dirname(__DIR__)` والسلسلة المجردة مع رفض `../`؛ أي صيغة مستقبلية خارج هذه العائلات (مثل تركيب متغيرات معقد) تبقى حدًا معروفًا، وتدهورها وقت التشغيل محتوى بـis_readable/graceful degradation، والبناء الفعلي يعبّئ كل ملفات التشغيل دائمًا.
- مطابقة رقم أصل ZIP لإصدار Release مفروضة في أداة البناء (اسم الأصل) وليس داخل مكتبة PUC — انظر وثيقة التكييف §3.3.3.
- سلامة vendor داخل الحزمة تُفحص مقابل composer.lock عبر installed.php (pretty + expanded fail-closed) وsanity محمل PUC، دون manifest موقّع لكل ملف — أقل من مطلق؛ يعوّضه أن خط النشر يبني vendor من القفل فورًا قبل البناء بـcomposer install --locked.
- ملاحظة الترخيص: GPL-2.0-or-later — **أُقر نهائيًا من المالك بتاريخ 2026-09-28** (كان مقترحًا بانتظار الإقرار).

**جولة تدقيق مستقلة بطلب المالك — 2026-09-25:** فاحص مطابقة/وظائفي + فاحص أمني عدائي (تأطير مضاد للتثبيت). النتيجة: P1 واحد (GH_REPO في مهمة النشر — مُصلح بوكيل منفذ) + ثمانية P3 دُمجت في خمسة تحصينات + إغلاق ملاحظتين من المتحقق الثالث (تضييق مطابقة الإصدار، عائلة dirname). متحقق ثالث: كل الإصلاحات file:line، بناء متطابق بايتيًا (ca6ac75a…983729)، 14/14، الحزم العدائية كلها مرفوضة. الحكم: VERIFIED. البنود 8 و9 أعلاه محدثة بأدلة الجولة.

**تدقيق مستقل لاحق — 2026-09-28 (رقعة camelCase-URL الأمنية):** عثر تدقيق مستقل على أربع فروقات غير مسجّلة في شجرة العمل — لوحظت بتاريخ 2026-09-28 بين 14:11 و14:12، وليست من جلسة دفعة 8، ومنشؤها «غير مؤكد المصدر في السجل»: (1) توسيع نمط regex في hal-mcp-abilities/integrations/blocks.php (سطر 214: `/(^|_)url$/i` → `/url$/i`)؛ (2) التوسيع ذاته على النمط المطابق للأحرف الصغيرة في hal-mcp-abilities/integrations/elementor.php (سطر 164) — يغلق التوسيعان فجوة مفاتيح camelCase المنتهية بـurl، مثل backgroundUrl/linkUrl، التي كانت تهرب من esc_url_raw؛ (3) تصحيح تعليق قديم في hal-mcp-abilities/includes/categories.php («four» → «five»)؛ (4) تغطية انحدار مطابقة في V04 (tests/local/content-integrations.php) لحالتي blocks وelementor معًا: مفاتيح backgroundUrl/linkUrl بقيم javascript: تُفرَّغ عبر esc_url_raw. حُكم التدقيق: REJECT لكونها غير مسجّلة، وقد أُبقيت لجدارتها الأمنية مع تدوين منشئها هنا. أُعيد تشغيل دورات الاختبار الخمس على الحالة المرقّعة بتاريخ 2026-09-28 فخرجت كلها خضراء: V01 14/14، V02 17/17، V03 13/13، V04 13/13، V05 10/10؛ وأُعيد بناء الحزمة والتحقق منها كاملًا بالبصمة الجديدة sha256 ab29dc943d80b1d03018f4d345ebe1cc814abb56e6f2106c901e8f2473ff1352 (بند 9 أعلاه). وإن قضى المالك لاحقًا بأن الرقعة غير مصرَّح بها، فلقطة دفعة 8 (sha256 cce108718b3aabf18f1e7bf794f51b7f5e0fcde00e566fd392e073e9c2039128) تبقى قابلة للاستعادة من أصل dist السابق والأساسيات (baselines).
