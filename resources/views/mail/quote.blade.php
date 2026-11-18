<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; font-size: 14px; color: #1f2937; line-height: 1.5;">
    <div style="max-width: 640px;">
        {!! nl2br(e($body)) !!}
        <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0 12px;">
        <p style="font-size: 12px; color: #6b7280;">
            {{ $company->name }}@if ($company->phone) · {{ $company->phone }}@endif @if ($company->email) · {{ $company->email }}@endif
            @if ($company->address)<br>{{ $company->address }}@if ($company->city), {{ $company->city }}@endif @endif
        </p>
    </div>
</body>
</html>
