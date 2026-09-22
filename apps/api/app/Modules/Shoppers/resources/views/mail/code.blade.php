<!doctype html>
<html lang="{{ $language }}" dir="{{ $language === 'he' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><title>{{ __('shoppers::ui.mail.subject', ['code' => $code], $language) }}</title></head>
<body style="margin:0;padding:24px;background:#f6f6f7;font-family:Arial,Helvetica,sans-serif;color:#111827">
    <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:14px;padding:24px">
        <p style="margin:0 0 12px;font-size:16px">{{ __('shoppers::ui.mail.greeting', ['shop' => $shopName], $language) }}</p>
        <p style="margin:0 0 18px;font-size:15px;line-height:1.6">{{ __('shoppers::ui.mail.body', ['minutes' => $minutes], $language) }}</p>
        <p style="margin:0 0 18px;font-size:32px;font-weight:700;letter-spacing:6px;text-align:center">{{ $code }}</p>
        <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.6">{{ __('shoppers::ui.mail.ignore', [], $language) }}</p>
    </div>
</body>
</html>
