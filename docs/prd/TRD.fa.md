# پلتفرم درخواست شغل Joinera — سند نیازمندی فنی (TRD)

**نسخه:** 1.6 · **فارسی** · **مخاطب:** مهندسی  
**مرتبط:** [PRD.fa](./PRD.fa.md) · [فازهای LVP ایمیل اپلای](./APPLY_EMAIL_LVP_PHASES.md) · [English](./TRD.md)  
**قفل اینتیک/اپلای:** هم‌تراز PRD 1.8 (۲۰۲۶-۱۱-۱۸) — بوردها = اینتیک؛ Mode B = سایت شرکت با AI کشف‌فرم + Playwright. (بخش‌های قدیمی میلگان/سوایپ در این فایل هنوز ممکن است عقب باشند؛ مسیر اپلای زیر معتبر است.)

---

## رویکرد

۱. هزینه کم AI/API؛ پیچیدگی کد بیشتر OK.  
۲. صف، درخواست idempotent، retry، مشاهده‌پذیری، ایزوله per-user.  
۳. مقیاس از حجم عملیات دستی فعلی بدون بازنویسی.  
۴. اول نرم‌افزار؛ Staff پلن B = پشتیبان.  
۵. ضدبلاک **سخت** است.

---

## زمینه سیستم

```text
پنل کاربر / Staff / Admin → API/BFF → سرویس‌ها
  احراز · پروفایل/پارس رزومه · هویت · اینتیک · مچ/صف
  PDF · ورکر درخواست · هشدار · گزارش
  (بدون پرداخت و بدون متن قیمت)

خارجی: سایت‌های شغل (Q12) · صندوق شخص ثالث · LLM/PDF
```

لندینگ مارکتینگ می‌ماند؛ پنل‌ها جدیدند. Monolith ماژولار OK اگر ورکرها و tenancy تمیز بمانند.

---

## سرویس‌ها

| سرویس | کار |
| --- | --- |
| Auth و RBAC | دستور create ادمین → provision کاربر؛ سشن؛ User/Staff/Admin |
| پروفایل + پارس رزومه | پروفایل ساخت‌یافته؛ آپلود → فیلد قابل ویرایش |
| هویت | میلباکس per-user + سکرت/سشن per-site؛ ACL break-glass |
| اینتیک + ناوگان مرورگر | اسکرپ روزانه فقط **جدید** از بوردهای DE (کشف + URL اپلای شرکت)؛ کانتکست ایزوله ≠ سشن اپلای |
| مچ | فیلتر قواعد → رتبه AI → `proposed`؛ Queue selected / force-queue |
| صف درخواست | Mode B، سقف (Q17)، idempotency؛ Pause روی بلاک |
| مدرک | تولید PDF + متادیتای هزینه (حالت‌های Q20) |
| ورکر درخواست | Mode B سایت شرکت: Playwright + AI کشف‌فرم → پر/آپلود/ارسال؛ بدون Easy-Apply بورد؛ بدون آداپتر per-employer |
| Inbox/Sync | اختیاری؛ وابسته به Q9 |
| Ops / Reporting / Notify | پلن B، متریک، هشدار |
| Billing | **فقط اگر** Q-Commercial = اشتراک داخل محصول |

---

## داده (منطقی)

**موجودیت‌ها:** User · Profile · CvAsset · MailboxIdentity (شامل دامنهٔ per-user) · SiteIdentity · Listing · Match · Application · DocArtifact · ApplyAttempt · StaffAccessAudit · BlockEvent

**پایپ‌لاین:** `matched → approved/queued → applying → submitted → reply_received → interview → rejected | offer`

- اعتبارسنجی انتقال سمت سرور.  
- میلباکس provisioned **فقط** وقتی کاربر حسابش را حذف کند پاک می‌شود — هرگز به‌خاطر رد شدن یک درخواست.  
- Idempotency: `user + listing + site`. Dedupe اینتیک: منبع + id خارجی / اثرانگشت.

---

## نیازمندی‌ها بر اساس حوزه

### اینتیک و مچ
- اسکرپ روزانه DE (لیست Q12)؛ فقط جدید؛ curated همان مدل.  
- غیر DE خاموش مگر Q33. مرورگر اینتیک ≠ سشن کاربر اپلای.  
- سطح‌ها: `recommended` در برابر `queued` در برابر `dismissed`. نامناسب‌های سخت وارد صف اصلی نشوند. مچ مجدد با تغییر پروفایل/آگهی. سوایپ پیشنهادی: بله → `queued`؛ نه → `dismissed`.

### اتواپلای و ضدبلاک
- dequeue: `preparing_docs` کامل → applying → submitted/fail.  
- اپلای با GMX + پروفایل/مدارک روی فرم **شرکت**؛ ایزوله مرورگر per-Candidate؛ بدون کوکی مشترک.  
- شبیه انسان: تأخیر تصادفی، ناوبری واقعی، محدودیت همزمانی per-Candidate.  
- ورکر سایت شرکت: باز کردن URL اپلای شرکت → کشف فیلد → نگاشت AI → پر/آپلود/ارسال → پارس تأیید. بدون Easy-Apply بورد. بدون آداپتر per-employer.  
- CAPTCHA/2FA/دیوار لاگین / فرم غیرقابل‌پر → BlockEvent، Pause، هشدار Staff (Q18).  
- سقف‌ها از env (Q17).  
- پلن B: break-glass audited + تکمیل دستی روی URL شرکت.

### مدارک
- فقط PDF. حالت‌های قابل پیکربندی برای Q20: `full_tailor` | `base_cv_plus_cover`.  
- کش رزومه پایه/قالب؛ رد شدن از LLM وقتی پر کردن قالب کافی است. بدون گیت تأیید در v1. ذخیره متای هزینه.

### ایمیل و امنیت
- **دامنه per-user:** Joinera یک دامنهٔ معمولی غیربرندی (ترجیحاً `.de`) می‌خرد؛ فعلاً بدون لندینگ — فقط DNS ایمیل.  
- `MailboxProvider`: Mailgun EU (جایگزین SES فرانکفورت). یک آدرس apply روی دامنهٔ همان کاربر. provision ناهمگام: `pending` → `ready` / `failed`. حذف provisioned **فقط با حذف حساب توسط خود کاربر**.  
- کاربر نمی‌خواند و جواب نمی‌دهد؛ Staff/Admin در پنل ادمین می‌توانند. بدون OAuth کاربر.  
- ادمین فقط create می‌زند؛ سیستم کاربر + دامنه + ایمیل را می‌سازد.  
- سکرت در KMS/vault؛ plaintext فقط از طریق break-glass با audit.  
- بدون کوکی/ذخیره بین کاربران. رمزنگاری در rest؛ TLS؛ least privilege ورکرها؛ rate-limit احراز/break-glass؛ حداقل PII در لاگ. جزئیات GDPR = Q11.

### API و پنل‌ها
- API/BFF نسخه‌دار HTTPS JSON؛ RBAC؛ Idempotency-Key روی enqueue.  
- رویدادها: listing.created، application.status_changed، identity.blocked.  
- کاربر: پروفایل، هویت، پیشنهادی (سوایپ) / صف، پایپ‌لاین، گزارش، حذف.  
- Admin/Staff: کاربران، صف‌ها، بلاک‌ها، break-glass+audit، متریک، نقش‌ها.

### مقیاس، مشاهده، تست
- برای ۱۰۰۰ کاربر همزمان روز اول سایز نشده (Q31). ورکر افقی؛ ایندکس روی user+status، اثرانگشت، idempotency.  
- متریک: اینتیک، عمق صف، موفقیت/بلاک/Staff، هزینه مدرک/AI، تأخیر. تریس enqueue→doc→apply.  
- تست: واحد (مچر/وضعیت/idempotency) · فیک adapter · صف/PDF · E2E استیجینگ · RBAC/حذف · لود سبک.

---

## قلاب‌های پیکربندی TBD

| TBD | پرچم |
| --- | --- |
| Q7 | **تصمیم.** `profile.readiness_gate` = `once`. یک‌بار Staff یا Admin. ویرایش بعدی گیت را باز نمی‌کند. |
| Q8 | **تصمیم (۷ نوامبر ۲۰۲۶).** `mailbox.mode` = `managed_provider`، Mailgun EU، **یک دامنه غیربرندی per-user** (ترجیحاً `.de`). نه `apply.joinera.de`، نه Gmail. الگوی local-part هنوز با PM باز است. |
| Q9 | **تصمیم.** کاربر نمی‌خواند و جواب نمی‌دهد. Staff و Admin می‌توانند. |
| Q11 | **مالکیت تصمیم شده:** صندوق مال Joinera است. TTL نگهداری هنوز باز است. |
| Q12 | `sites.v1[]` هنوز باز |
| Q17 | `caps.daily` / `caps.weekly` هنوز باز |
| Q18 | `challenge.policy` هنوز باز. حداقل: تشخیص، توقف، هشدار Staff. |
| Q20 | **تصمیم.** دسته یکسان: رزومه پایه + کاور جدید. دسته متفاوت: رزومه جدید و کاور جدید. دسته نامشخص: حدس نزن. |
| Q31 | استخر ورکر / SLO هنوز باز |
| Q33 | `sites.allow_non_de` هنوز باز (پیش‌فرض false) |
| Q-Commercial | **تصمیم.** بدون پرداخت و اشتراک. در محصول از پول، پرداخت، یا «رایگان» چیزی نگو. |
