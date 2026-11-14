<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $reference }}</title>
    <style>
        @page { size: A4 portrait; margin: 20mm; }
        @font-face {
            font-family: "MaprCarlito";
            src: url("{{ $font_regular }}") format("truetype");
            font-weight: 400;
        }
        @font-face {
            font-family: "MaprCarlito";
            src: url("{{ $font_bold }}") format("truetype");
            font-weight: 700;
        }
        * { box-sizing: border-box; }
        body { margin: 0; color: #000; background: #fff; font-family: MaprCarlito, Arial, sans-serif; font-size: 12pt; line-height: 1.35; }
        .logos { text-align: center; margin: 0 0 8px; }
        .logos img { height: 18mm; width: auto; margin: 0 3mm; vertical-align: middle; }
        .center { text-align: center; margin: 0; }
        h1 { font-size: 14pt; margin: 8px 0 2px; }
        h2 { font-size: 12pt; margin: 12px 0 6px; }
        .notice { margin: 6px 0 10px; font-size: 9pt; text-align: center; }
        table { width: 100%; border-collapse: collapse; background: #fff; margin: 0 0 10px; }
        thead { display: table-header-group; }
        th, td { border: 1px solid #000; background: #fff; color: #000; padding: 4px 6px; vertical-align: top; text-align: left; font-weight: 400; }
        th { width: 42%; }
        .impact th, .impact td { width: auto; text-align: center; }
        .signatures { page-break-inside: avoid; margin-top: 14px; }
        .signature-grid { width: 100%; }
        .signature-grid td { width: 50%; border: 0; padding: 8px 10px 0 0; }
        .signature-line { height: 16mm; border-bottom: 1px solid #000; margin: 8px 0 4px; }
        .signature-line img { height: 14mm; width: auto; }
        .photos td { width: 50%; border: 0; padding: 4px 6px 8px 0; page-break-inside: avoid; }
        .photos img { width: 78mm; height: auto; max-height: 70mm; }
        .muted { color: #000; font-size: 10pt; }
        .footer { position: fixed; left: 0; right: 0; bottom: -12mm; text-align: center; font-size: 9pt; }
        .preview-chrome { margin: 0 0 14px; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; background: #f8fafc; font-family: Arial, sans-serif; font-size: 11pt; }
        .preview-chrome a { color: #0f3d6e; text-decoration: none; font-weight: 700; }
        .preview-chrome a:hover { text-decoration: underline; }
        @media print {
            .preview-chrome { display: none !important; }
        }
    </style>
</head>
<body>
    @if (!empty($back_url))
        <div class="preview-chrome no-print">
            <a href="{{ $back_url }}">{{ $back_label ?? 'Back' }}</a>
            <span class="muted"> · {{ $reference }}</span>
        </div>
    @endif
    <div class="footer">MapR | Building Damage Assessment and Resident Impact Report</div>
    <div class="logos">
        @foreach ($logos as $logo)
            <img src="{{ $logo['path'] }}" alt="{{ $logo['alt'] }}">
        @endforeach
    </div>
    <p class="center">Republic of the Philippines</p>
    <p class="center">Province of Surigao del Sur</p>
    <p class="center">City of Tandag</p>
    <p class="center"><strong>{{ $office_line }}</strong></p>
    <h1 class="center">{{ $title }}</h1>
    @if (!empty($subtitle))
        <p class="center muted">{{ $subtitle }}</p>
    @endif
    <p class="notice">Generated report from the saved MapR record. This is not the original imported attachment.</p>
    <h2>MapR Assessment Record</h2>
    <p>Report reference: {{ $reference }}</p>
    <p>Date prepared: {{ $prepared_at }}</p>
    <h2>Report identification</h2>
    <table>
        <thead>
            <tr><th>Field</th><th>Recorded details</th></tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr><th>{{ $row['label'] }}</th><td>{{ $row['value'] }}</td></tr>
            @endforeach
        </tbody>
    </table>
    @if (!empty($verification))
        <h2>Building and location verification</h2>
        <table>
            <thead>
                <tr><th>Field</th><th>Recorded details</th></tr>
            </thead>
            <tbody>
                @foreach ($verification as $row)
                    <tr><th>{{ $row['label'] }}</th><td>{{ $row['value'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
    <h2>Potential resident impact</h2>
    <table class="impact">
        <thead>
            <tr>
                <th>Linked buildings</th>
                <th>Households</th>
                <th>Residents</th>
                <th>Validation</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $impact['buildings'] }}</td>
                <td>{{ $impact['households'] }}</td>
                <td>{{ $impact['residents'] }}</td>
                <td>{{ $impact['validation'] }}</td>
            </tr>
        </tbody>
    </table>
    <h2>Photographic evidence</h2>
    @if (count($photos ?? []) === 0)
        <p>No photographs were saved with this report.</p>
    @else
        <table class="photos">
            @foreach (array_chunk($photos, 2) as $pair)
                <tr>
                    @foreach ($pair as $photo)
                        <td>
                            @if ($photo['path'])
                                <img src="{{ $photo['path'] }}" alt="{{ $photo['caption'] }}">
                            @else
                                <p>Photograph file is unavailable.</p>
                            @endif
                            <p>{{ $photo['caption'] }}</p>
                        </td>
                    @endforeach
                    @if (count($pair) === 1)
                        <td></td>
                    @endif
                </tr>
            @endforeach
        </table>
    @endif
    @if (!empty($damage_assessments['buildings'] ?? null))
        <h2>Building Damage Assessment</h2>
        @foreach (($damage_assessments['buildings'] ?? []) as $assessment)
            <table>
                <thead>
                    <tr><th>Field</th><th>Recorded details</th></tr>
                </thead>
                <tbody>
                    @if (count($damage_assessments['buildings']) > 1)
                        <tr><th>Assessed building</th><td>{{ $assessment['building_reference'] }}</td></tr>
                    @endif
                    <tr><th>Building Status</th><td>{{ $assessment['building_status'] }}</td></tr>
                    <tr><th>Estimated Cost Assessment</th><td>{{ $assessment['estimated_cost'] }}</td></tr>
                    @if (!empty($assessment['assessor']))
                        <tr><th>Assessed By</th><td>{{ $assessment['assessor'] }}</td></tr>
                    @endif
                    @if (!empty($assessment['assessed_at']))
                        <tr><th>Assessment Date</th><td>{{ $assessment['assessed_at'] }}</td></tr>
                    @endif
                    @if (!empty($assessment['classification_source']))
                        <tr><th>Classification Source</th><td>{{ $assessment['classification_source'] }}</td></tr>
                    @endif
                    @if (!empty($assessment['remarks']) && $assessment['remarks'] !== 'Not recorded')
                        <tr><th>Assessment Remarks</th><td>{{ $assessment['remarks'] }}</td></tr>
                    @endif
                </tbody>
            </table>
            @if (
                !empty($assessment['occupancy_key'])
                || !empty($assessment['construction_type'])
                || !empty($assessment['construction_grade'])
                || !empty($assessment['total_floor_area_m2'])
                || !empty($assessment['assessed_floor_area_m2'])
                || !empty($assessment['unit_cost_php'])
            )
                <h2>Damage assessment criteria</h2>
                <table>
                    <thead>
                        <tr><th>Field</th><th>Recorded details</th></tr>
                    </thead>
                    <tbody>
                        <tr><th>Occupancy / Use</th><td>{{ $assessment['occupancy_key'] ?? 'Not recorded' }}</td></tr>
                        <tr><th>Construction Type</th><td>{{ $assessment['construction_type'] ?? 'Not recorded' }}</td></tr>
                        <tr><th>Valuation Grade</th><td>{{ $assessment['construction_grade'] ?? 'Not recorded' }}</td></tr>
                        <tr><th>Total Floor Area</th><td>{{ $assessment['total_floor_area_m2'] ?? 'Not recorded' }}</td></tr>
                        <tr><th>Assessed Damaged Floor Area</th><td>{{ $assessment['assessed_floor_area_m2'] ?? 'Not recorded' }}</td></tr>
                        <tr><th>Applicable Unit Construction Cost</th><td>{{ $assessment['unit_cost_php'] ?? 'Not recorded' }}</td></tr>
                        @if (!empty($assessment['criteria_version']) && $assessment['criteria_version'] !== 'Not recorded')
                            <tr><th>Criteria Version</th><td>{{ $assessment['criteria_version'] }}</td></tr>
                        @endif
                    </tbody>
                </table>
            @endif
        @endforeach
        @if (count($damage_assessments['buildings']) > 1)
            <p><strong>Aggregate estimated cost:</strong> {{ $damage_assessments['aggregate'] }}</p>
            @if (!empty($damage_assessments['incomplete']))
                <p class="muted">Incomplete building estimates: {{ implode(', ', $damage_assessments['incomplete']) }}</p>
            @endif
        @endif
    @endif
    <section class="signatures">
        <h2>Report declaration and endorsements</h2>
        <p class="muted">A pending line is the configured officeholder. It is not a signature on this report. A completed line names the person who signed.</p>
        <table class="signature-grid">
            @foreach (array_chunk($signatures, 2) as $pair)
                <tr>
                    @foreach ($pair as $signature)
                        <td>
                            <strong>{{ $signature['title'] }}</strong>
                            <div class="signature-line">
                                @if ($signature['image'])
                                    <img src="{{ $signature['image'] }}" alt="Executed signature">
                                @endif
                            </div>
                            <div>{{ $signature['name'] }}</div>
                            <div class="muted">{{ $signature['position'] }}</div>
                            <div class="muted">{{ $signature['caption'] }}</div>
                        </td>
                    @endforeach
                    @if (count($pair) === 1)
                        <td></td>
                    @endif
                </tr>
            @endforeach
        </table>
    </section>
</body>
</html>
