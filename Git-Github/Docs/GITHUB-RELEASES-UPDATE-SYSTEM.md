# نظام التحديث عبر GitHub Releases — التكييف المعتمد لإضافة HAL MCP Integration Abilities

| | |
|---|---|
| **الحالة** | نسخة مكيفة من المرجع العام لجهاز المالك، **خاصة بهذه الإضافة حصرًا**. التنفيذ المحلي مكتمل (G01–G11)؛ الأفعال التشغيلية (إنشاء المستودع، أول وسم ونشر، تثبيت على موقع) لم تنفذ بعد وتُسجل في `Git-Github/Docs/ADOPTION-LOG.md` |
| **المصدر المُكيَّف** | `website/plugins/Git-Github/Docs/GITHUB-RELEASES-UPDATE-SYSTEM.md` (إصدار 2.3) — قُلّصت أمثلة المشاريع الأخرى، ولم يُنسخ منها: قسم التحقق الخارجي المقيد زمنيًا وworkflowه وtests/external، ولا ملحقات الجهاز/الحساب، ولا سجلات نجاح مشاريع أخرى. هذا الملف ليس داخل أي حزمة توزيع |
| **هوية التوزيع** | repository/Update URI: `https://github.com/hossamadellaw/hal-mcp-integration-abilities` — slug: `hal-mcp-integration-abilities` — الملف الرئيسي: `hal-mcp-integration-abilities.php` — bootstrap داخلي: `hal-mcp-abilities/hal-mcp-abilities.php` |
| **إصدار ووسم** | 2.0.0 — وسم `v2.0.0`؛ بعده `vX.Y.Z` — أصل التوزيع `hal-mcp-integration-abilities-2.0.0.zip` وتجزئته `.sha256` |
| **PUC** | `yahnis-elsts/plugin-update-checker:~5.7.0` — namespace `YahnisElsts\PluginUpdateChecker\v5p7` حصرًا |

### استثناء الإغلاق المحلي (مثبت في خارطة التنفيذ)

إغلاق تنفيذ بنود G01–G11 **لا يشترط** تشغيل GitHub Actions أو نشر Release أو تنزيل أصل من موقع حي أو اتصال مزود. فحوص هذا التكييف محلية: صياغة PHP، `composer validate/audit`، بناء الحزمة وتحققها عبر `scripts/build-release.php`. الأدلة التشغيلية (نجاح workflow، Release منشور، تحديث فعلي على موقع) تُسجل في `ADOPTION-LOG.md` عند وجودها فقط، ولا تُدّعى مسبقًا.

---

## 1. الفلسفة والدورة

ثلاثة مكوّنات منفصلة:

```text
┌───────────────────────────────────┐   ┌──────────────────────────┐   ┌──────────────────────────┐
│ 1) الإضافة (includes/updater.php)  │   │ 2) GitHub Releases + ZIP  │   │ 3) .github/workflows/     │
│ مكتبة PUC تقارن النسخة بالمنشور    │◄──│ مصدر التوزيع المعتمد      │◄──│ release.yml يبني ويفحص    │
│ وفق هوية هذا المشروع               │   │ لا يُستبدل بعد النشر      │   │ وينشر                     │
└───────────────────────────────────┘   └──────────────────────────┘   └──────────────────────────┘
```

الدورة: رفع الأرقام الثلاثة → commit/push إلى main → وسم `vX.Y.Z` مُعلَّق ودفع → workflow يفشل أو ينجح بصريًا → الموقع يكشف التحديث عبر PUC → تحديث بضغطة عبر مسار ووردبريس القياسي. الحزمة الموزعة تحمل `vendor/` مبنيًا مسبقًا؛ الموقع لا يشغّل Composer أبدًا. `main` والوسم منفصلان: التطوير لا يمس المنشور.

## 2. البيئة التشغيلية

| # | المتطلب | قيمة هذا المشروع |
|---|---|---|
| 1 | WordPress | `Requires at least: 6.9` (ترويسة الإضافة) |
| 2 | PHP | `Requires PHP: 8.0` و`platform.php: 8.0.0` في composer.json — تطابق دلالي بعد إكمال patch |
| 3 | Composer 2 | تطوير وCI لبناء `vendor/` من composer.lock؛ composer.lock يُلتزم |
| 4 | مستودع عام | المسار الافتراضي: بلا توكن على الموقع |
| 5 | مستودع خاص | مسار مشروط قرار مالك: توكن Fine-grained للقراءة فقط يُعرَّف بـ`HAL_MCP_ABILITIES_GITHUB_TOKEN` من wp-config.php/الاستضافة، لا في Git؛ لا يُعتمد قبل اختبار كشف وتنزيل موثق |
| 6 | Multisite | مشروط: لا يُعلن دعمه قبل اختبار network activation — لم يُختبر |

## 3. تجهيز الإضافة — المنفذ محليًا، القيم الفعلية

### 3.1 Composer (G04/G05)

`composer.json` في جذر الإضافة: اعتماد runtime واحد `yahnis-elsts/plugin-update-checker` بقيد محصور `~5.7.0` (محصور في minor الموافق لـnamespace v5p7 — قيد أوسع يكسر `class_exists`). بلا scripts وبلا composer-plugins — لذا يستخدم workflow `--no-scripts --no-plugins`. `composer.lock` محلول وقائم (PUC v5.7) ويلتزم في Git؛ `vendor/` مبني محليًا ويبنى في CI ولا يلتزم.

### 3.2 الثوابت (في الملف الرئيسي، بنمط if(!defined) قابل للتجاوز)

`HAL_MCP_ABILITIES_VERSION` = '2.0.0' — `HAL_MCP_ABILITIES_DIR` — `HAL_MCP_ABILITIES_PLUGIN_FILE` (المصدر لكل مسارات updater). ترويسة الإضافة تحمل `Update URI:` فريدًا لا يشير إلى WordPress.org.

### 3.3 ربط المحدِّث (G03 — `hal-mcp-abilities/includes/updater.php`)

المنطق المعمول به، وكل حارس فيه منع وقوعًا موثقًا في المرجع:

1. **السياق الصحيح فقط:** يعمل عند وجود `HAL_MCP_ABILITIES_PLUGIN_FILE` — نسخة mu-plugin القديمة أو require مباشر معطل لا تحصل على updater.
2. **namespace حصري:** `class_exists` لـ`YahnisElsts\PluginUpdateChecker\v5p7\PucFactory` قبل `require` وبعده؛ وجود vendor وحده لا يثبت اكتمال المكتبة؛ الاسم العام `PucFactory` لم يعد مسجلًا في الإصدارات الحديثة — استخدامه خطأ فادح.
3. **فشل مغلق للأصل:** `enableReleaseAssets('/^hal-mcp-integration-abilities-[0-9]+\.[0-9]+\.[0-9]+\.zip$/i', VcsApi::REQUIRE_RELEASE_ASSETS)` — بلا fallback لأرشيف المصدر؛ ZIP واحد مطابق لكل Release. **نقطة فرض مطابقة رقم الأصل للإصدار هي خط النشر حصرًا:** أداة `build-release.php` ترفض اسم أصل لا يحمل إصدار الوسم المتوقع (`--expect-version`)، بينما تختار مكتبة PUC نفسها أول أصل مطابق للـregex دون مقارنة رقمية — لا تُعتمد عليها في هذه المطابقة.
4. **احتواء Throwable:** كل تهيئة المحدث داخل try/catch؛ العطل لا يسقط الإضافة ولا الموقع؛ رسالة إدارية عامة منقحة (بلا توكن أو URL أو stack) + سطر WP_DEBUG عام.
5. **بلا أسرار على الموقع العام:** التوكن ثابت فارغ افتراضيًا، ولا يُقرأ إلا إن عرّفه المالك خارج Git.

### 3.4 اتساق رقم الإصدار — ثلاثة مواضع حرفية

| الموضع | الملف |
|---|---|
| ترويسة `Version:` | `hal-mcp-integration-abilities.php` |
| ثابت `HAL_MCP_ABILITIES_VERSION` | `hal-mcp-integration-abilities.php` |
| `Stable tag:` | `readme.txt` |

workflow الإصدار يرفض أي وسم لا تطابق قيمُه الثلاث معه. أداة البناء تعيد فحص الاتساق داخل الحزمة نفسها.

### 3.5 خريطة التكييف — مكتملة بهوية هذا المشروع

| الحقل | القيمة الفعلية |
|---|---|
| بادئة الثوابت | `HAL_MCP_ABILITIES_` |
| Update URI | `https://github.com/hossamadellaw/hal-mcp-integration-abilities` |
| slug ومجلد التوزيع | `hal-mcp-integration-abilities` — ثابت بلا لاحقة إصدار |
| ASSET_REGEX | `/^hal-mcp-integration-abilities-[0-9]+\.[0-9]+\.[0-9]+\.zip$/i` |
| platform PHP | `8.0.0` |
| قائمة البناء | المدخل، `uninstall.php`، `readme.txt`، `LICENSE`، `hal-mcp-abilities/` (bootstrap + includes/ + abilities/ + integrations/ + assets/)، `vendor/` — مستبعد: `ABILITIES-REGISTRY.md` وcomposer.json وcomposer.lock وكل ما ليس تشغيليًا |
| أنماط التحقق | `^[ \t]*\*[ \t]*Version:` و`define\( 'HAL_MCP_ABILITIES_VERSION', '` و`^Stable tag:` و`^[ \t]*\*[ \t]*Update URI:` |
| الحدود | WP 6.9 / PHP 8.0 كما في الترويسة |

## 4. خط الإصدار الآلي (G10) وأداة الحزمة (G11)

**المرجع الوحيد لسلوك الـworkflow هو الملف نفسه:** `.github/workflows/release.yml`. مبسط بمهمتين: `build-and-verify` (صلاحية قراءة فقط) و`publish` (contents: write فقط لنشر Release). لا ci.yml ولا workflows مراقبة.

مهمة البناء والتحقق: checkout للوسم نفسه (`fetch-depth: 0`، `persist-credentials: false`) → حارس PHP → شكل الوسم `vX.Y.Z` → وسم مُعلَّق واحتواء commit في origin/main → مطابقة الأرقام الثلاثة وUpdate URI مع الوسم (أنماط مقشّرة بـ`|| true` وفحص فارغ مسمى) → `composer validate --strict` ثم install مقفول بلا dev ثم `composer audit --locked --no-dev` → بناء الحزمة وتحققها عبر أداة G11 → رفع artifact. actions الثلاثة الرسمية مثبتة بـSHA كامل محلول من API رسمي مع تعليق `# v4` (hardening: لا تحرك مع تحرك الوسم الأعلى).

مهمة النشر: تنزيل artifact → `sha256sum -c` بعد النقل → Release مسودة بأصل ZIP وتجزئته → `--draft=false`. خطوة النشر تضبط `GH_REPO: ${{ github.repository }}` صراحة لأن gh بلا checkout لا يحل المستودع من GITHUB_REPOSITORY وحده (وقوع تدقيق فعلي 2026-09-25). حارس إعادة التشغيل: أي Release موجود لنفس الوسم يفشل المهمة برسالة مسماة — لا استبدال منشور ولا حذف أصول لتجاوز تعارض؛ Draft فاشل يحذفه المالك يدويًا.

**فحص الحزمة موضع واحد (G11):** `php scripts/build-release.php build|verify` — مدخل الإضافة وuninstall وreadme وLICENSE وملفات التشغيل وvendor/؛ اشتقاق مراجع require/assets/autoload من الكود المعبأ نفسه (21 مرجعًا وقت التنفيذ المحلي)؛ رفض تسرب docs/tests/Git-Github/.github/scripts/.env/composer.* في الجذر/symlink/traversal/تكرار؛ مجلد أعلى واحد بالslug؛ CRC لكل مدخلة؛ SHA256 sidecar متوافق مع sha256sum؛ هوية الحزمة تُقرأ من داخل الـZIP لا من ملفات الجهاز. لا يُكرر هذا الفحص في ملف آخر ولا في test suite.

## 5. إجراء الإصدار (يتكرر — بالترتيب)

```bash
# 1) ارفع الثلاثي (ترويسة + ثابت + Stable tag) وأضف بند Changelog صادقًا.
# 2) تحقق محلي:
composer validate --strict && composer audit --locked --no-dev
php scripts/build-release.php build --out dist
php scripts/build-release.php verify dist/hal-mcp-integration-abilities-X.Y.Z.zip --expect-version X.Y.Z
# 3) تجهيز مسارات محددة فقط، مراجعة git diff --cached كاملًا، ثم commit بصيغة chore(release): bump to X.Y.Z
# 4) git tag -a vX.Y.Z -m "HAL MCP Integration Abilities X.Y.Z" ثم push الوسم — هذا زر الإطلاق.
# 5) راقب Actions حتى نتيجة نهائية؛ لا تعتمد زمنًا ثابتًا.
# 6) تحقق من الـRelease: غير Draft، والـZIP و.sha256 موجودان بالاسم الصحيح، وassets[].digest بصيغة sha256:<64-هيكس> تطابق.
```

قاعدة ذهبية: لا يُعاد استخدام وسم منشور أبدًا؛ إصدار خاطئ = رقم تصحيحي جديد عبر الدورة كاملة. لا `git add -A` أعمى.

## 6. قائمة التحقق بعد نشر كل إصدار

ينفذ workflow الفحوص القاتلة قبل النشر؛ وبعد أول إصدار وعند أي تغيير بالاعتماديات أو قائمة البناء، يُعاد فحص الأصل المنشور نفسه (تنزيل + هذه القائمة):

- [ ] كل ملفات `require_once` في bootstrap وmodules موجودة داخل الـZIP (فحص closure أداة G11 يعاد على الحزمة المنشورة)
- [ ] مجلد أصول `hal-mcp-abilities/assets/` كامل داخل الـZIP
- [ ] مجلد أعلى وحيد بالslug
- [ ] ترويسة الملف الرئيسي داخل الـZIP تحمل الرقم الجديد وUpdate URI المتوقع حرفيًا
- [ ] `vendor/` موجود ويحتوي PUC وautoload كاملًا
- [ ] `.github/` و`docs/` و`tests/` و`scripts/` و`Git-Github/` وأسرار غير موجودة
- [ ] `readme.txt` داخل الـZIP بنفس Stable tag
- [ ] SHA256 المنزل يطابق `.sha256` المنشور و`assets[].digest`
- [ ] `draft === false` و`prerelease` صريحة

## 7. جانب الموقع (دورة التحديث الفعلية)

الإضافة المثبتة من حزمة Release (أو نفس المصدر). الكشف آلي دوري وفق PUC/WordPress والتخزين المؤقت؛ الكشف الفوري من: تحديثات ← «تحقق مجددًا» (إن لم يظهر فورًا: انتظر ~10 دقائق وتأكد أن المنشور أعلى فعليًا من المثبت — التطابق لا يولد تحديثًا). الافتراضي: كشف آلي + تحديث يدوي بضغطة؛ التحديث الخلفي الكامل opt-in لكل موقع بعد staging وbackup. تحقق ما بعد التحديث إلزامي: رقم الإصدار، عمل الإضافة، لا أخطاء في debug.log.

## 8. استكشاف الأخطاء (أعراض ← سبب ← حل)

| العرض | السبب | الحل |
|---|---|---|
| run يفشل خلال ثوانٍ بلا رسالة | نمط grep بـ`^` لا يطابق ترويسة docblock | الأنماط المحصّنة في release.yml (`|| true` + فحص فارغ) لا تُختصر |
| تحديث يظهر ثم يكسر الموقع | fallback لأرشيف المصدر أو أصل غير مقصود | تأكد من `REQUIRE_RELEASE_ASSETS` وregex حصري وZIP واحد مطابق |
| fatal يذكر `PucFactory` العام | استخدام الاسم العام المزال | namespace `…\v5p7\PucFactory` حصرًا — موجود في updater.php |
| التحديث لا يظهر إطلاقًا | (أ) المنشور ≤ المثبت (ب) تخزين مؤقت (ج) اسم الأصل لا يطابق regex (د) الخادم محجوب عن api.github.com | راجعها بالترتيب |
| workflow يفشل عند إعادة نفس الوسم | حماية مقصودة | رقم جديد؛ لا حذف Release منشور؛ Draft فاشل فقط يحذفه المالك |

## 9. الأمن

**حمايات مستودع — أفعال مالك في واجهة GitHub (إعدادات عند التبني، لا ملفات اختراع):** 2FA على الحساب؛ حماية `main` (منع force-push وحذف)؛ حماية وسوم `v*` (منع حذف/نقل)؛ Immutable Releases عند توفرها؛ SECURITY.md مع تفعيل Private vulnerability reporting — كتابة الملف لا تفعّله بحد ذاتها. الـworkflow يستخدم `GITHUB_TOKEN` المدمج بـ`contents: write` في مهمة النشر فقط — لا PAT ثابت.

**قواعد الكود وpipeline:** أسرار لا تدخل Git ولا logs ولا ترويسات إشعارات؛ فشل المحدث محتوى ومشخص بعام؛ ما يُفحص قبل النشر هو ما يُنشر (لا ZIP يدوي خارج الـpipeline)؛ سجل SHA256 لكل أصل منشور؛ فحص أسرار وPII في `git diff --cached` قبل كل commit.

## 10. تعريف النجاح — طبقتان منفصلتان

**أ) التنفيذ المحلي (G01–G11) — مكتمل:**
- [x] composer.json بقيد `~5.7.0` وplatform 8.0.0 وcomposer.lock محلول (PUC v5.7) وvendor مبني
- [x] includes/updater.php بالحواجز الخمسة §3.3
- [x] release.yml بمهمتين وحدود صلاحية مقسمة وحواريات مطابقة
- [x] build-release.php: بناء وتحقق محلي ناجح + رفض سلبي لخلط إصدار/تسرب docs/composer.json/.env
- [x] readme.txt وLICENSE وSECURITY.md و.gitignore مطابقة الهوية
- [x] php -l نظيف لكل ملف PHP جديد/مغير

**ب) تشغيلي — معلق بأفعال المالك (يُسجل في ADOPTION-LOG.md عند التحقق):**
- [ ] إنشاء المستودع (فارغ تمامًا) وحمايات §9
- [ ] أول push ثم وسم `v2.0.0` مُعلَّق → نجاح Actions
- [ ] Release منشور بأصل مطابق وتجزئة وdigest
- [ ] تثبيت من حزمة الـRelease على موقع وعرض التحديث عبر «تحقق مجددًا»
- [ ] تحديث فعلي بمسار ووردبريس ثم تحقق §7

لا تُعلّم خانة من (ب) دون دليل تشغيلي؛ نجاح المحلي لا يثبت التشغيل.

## 11. ما لا يدخل هذه النسخة

- التحقق الخارجي المقيد زمنيًا (time-bounded-target-read-only-release-verification) وworkflowه وtests/external — ليست جزءًا من updater وليست شرط قبول بتوجيه المالك.
- ملحقات الجهاز/الحساب من المرجع العام، وسجلات نجاح مشاريع أخرى، وتقارير verifier خارجية — لا تنسخ إلى Git ولا إلى الحزمة.
- التكيف المستقبلي: أي تغيير على identity/husks هنا يجب أن يواكبه تحديث updater.php وrelease.yml وbuild-release.php معًا؛ المرجع الأحدث يقدم على لقطة هذا الملف عند تعارض سلوك خدمة خارجية.
