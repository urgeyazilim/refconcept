{{--
    The stand-in for a bank's 3DS page.

    Deliberately plain. It is a prop for tests and local development, not part of the
    product, and dressing it up to look like a real bank would put a phishing template in
    the repository. It says what it is at the top for the same reason.

    Served only where the fake gateway is enabled; the route 404s anywhere real money
    moves.
--}}
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test 3D Secure — RefConcept</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f4f4f5; margin: 0; padding: 2rem; color: #18181b; }
        main { max-width: 26rem; margin: 4rem auto; background: #fff; border: 1px solid #e4e4e7; border-radius: 12px; padding: 2rem; }
        h1 { font-size: 1.125rem; margin: 0 0 .25rem; }
        p { font-size: .875rem; color: #52525b; line-height: 1.6; }
        .amount { font-size: 1.75rem; font-weight: 600; margin: 1.5rem 0; }
        button { width: 100%; padding: .75rem 1rem; font-size: .9375rem; border-radius: 8px; border: 1px solid transparent; cursor: pointer; margin-bottom: .625rem; }
        .approve { background: #18181b; color: #fff; }
        .decline { background: #fff; color: #b91c1c; border-color: #e4e4e7; }
        .note { background: #fef3c7; border: 1px solid #fde68a; border-radius: 8px; padding: .625rem .75rem; font-size: .8125rem; color: #78350f; }
    </style>
</head>
<body>
<main>
    <p class="note">Bu sayfa gerçek bir banka sayfası değildir. RefConcept test ödeme sağlayıcısının 3D Secure adımını taklit eder.</p>

    <h1>Ödemeyi doğrulayın</h1>
    <p>İşlem numarası: <code>{{ $externalId }}</code></p>

    <div class="amount">{{ $amount }} {{ $currency }}</div>

    <button class="approve" id="approve" type="button">Onaylıyorum</button>
    <button class="decline" id="decline" type="button">Vazgeç</button>

    <p id="status"></p>
</main>

<script>
    /*
     * The semicolons are load-bearing.
     *
     * Blade swallows the newline that follows a directive, so a json directive ends its
     * line and the next statement runs straight on from it — three declarations collapse
     * onto one line and the whole script is a syntax error. The page then renders
     * perfectly and does nothing at all when clicked, which is a memorable half hour.
     */
    const complete = @json($completeUrl);
    const returnUrl = @json($returnUrl);
    const status = document.getElementById('status');

    async function finish(outcome) {
        status.textContent = 'İşleniyor…'

        await fetch(complete, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ outcome }),
        })

        window.location.href = returnUrl
    }

    document.getElementById('approve').addEventListener('click', () => finish('captured'))
    document.getElementById('decline').addEventListener('click', () => finish('failed'))
</script>
</body>
</html>
