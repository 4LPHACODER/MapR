<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>MapR CDRRMO draft report</title>
    <style>
        @font-face {
            font-family: Roboto;
            font-weight: 400;
            src: url('{{ base_path('resources/fonts/Roboto-Regular.ttf') }}') format('truetype');
        }
        @font-face {
            font-family: Roboto;
            font-weight: 700;
            src: url('{{ base_path('resources/fonts/Roboto-Bold.ttf') }}') format('truetype');
        }
        body { font-family: Roboto, Arial, sans-serif; color: #0f172a; font-size: 12px; }
        h1, h2 { color: #123b70; }
        .banner { border: 1px solid #e2e8f0; padding: 8px 12px; background: #eff6ff; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        th, td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; }
        .muted { color: #64748b; }
        .sign { width: 48%; display: inline-block; vertical-align: top; margin-top: 36px; }
        img.logo { height: 48px; }
    </style>
</head>
<body>
    <p>
        <img class="logo" src="{{ public_path('images/cdrrmo-logo.jpg') }}" alt="CDRRMO">
        <img class="logo" src="{{ public_path('images/tandag-city-logo.webp') }}" alt="Tandag City">
    </p>
    <h1>CDRRMO geographic summary</h1>
    <div class="banner"><strong>Status:</strong> {{ $status }}. This document is not approved and contains no signature.</div>
    <p class="muted">Generated {{ $generated_at->timezone('Asia/Manila')->format('Y-m-d H:i T') }}</p>

    @if ($error)
        <p>{{ $error }}</p>
    @elseif (! $estimate)
        <p>Draw an assessment polygon on the map to include live counts. No invented statistics are shown.</p>
    @else
        <p>{{ $estimate['label'] }}</p>
        <p>Spatial rule: {{ $estimate['spatial_predicate'] }}</p>
        <table>
            <thead>
                <tr><th>Measure</th><th>Official verified</th><th>Source registry (unverified)</th></tr>
            </thead>
            <tbody>
                <tr><td>Buildings</td><td>{{ $estimate['official']['buildings'] }}</td><td>{{ $estimate['source_registry_unverified']['buildings'] }}</td></tr>
                <tr><td>Households</td><td>{{ $estimate['official']['households'] }}</td><td>{{ $estimate['source_registry_unverified']['households'] }}</td></tr>
                <tr><td>Residents</td><td>{{ $estimate['official']['residents'] }}</td><td>{{ $estimate['source_registry_unverified']['residents'] }}</td></tr>
            </tbody>
        </table>
        <p>Verified damaged-building method: {{ $estimate['verified_damaged_buildings']['residents'] }} residents (no field verifications yet).</p>
        <p>Missing verified building locations: {{ $estimate['missing_data']['buildings_without_verified_point'] }}.</p>
        <p>Verified vulnerability records available: {{ $estimate['missing_data']['verified_vulnerability_records'] }}.</p>
    @endif

    <h2>Sources</h2>
    <ul>
        @foreach ($sources as $source)
            <li>{{ $source }}</li>
        @endforeach
    </ul>

    @foreach ($signatories as $signatory)
        <div class="sign">
            <p>{{ $signatory['title'] }}</p>
            <p><strong>{{ $signatory['name'] }}</strong><br>
            {{ $signatory['position'] }}</p>
            <p class="muted">Unsigned</p>
        </div>
    @endforeach
</body>
</html>
