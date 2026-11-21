<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; font-size: 14px; color: #1f2937; line-height: 1.5;">
    <div style="max-width: 640px;">
        {{-- $message est réservé par Laravel (objet du message, pour intégrer l'image) --}}
        @php [$messageText, $signatureText] = array_pad(explode("\n-- \n", $body, 2), 2, null); @endphp
        @markdown($messageText)
        @if ($signatureText)
            <div style="margin-top: 20px; padding-top: 10px; border-top: 1px solid #e5e7eb; font-size: 13px; color: #374151;">
                {!! nl2br(e($signatureText)) !!}
                {{-- Image intégrée au message ($message n'existe qu'à l'envoi, pas dans un simple aperçu) --}}
                @if ($signatureImage && isset($message))
                    <div style="margin-top: 8px;">
                        <img src="{{ $message->embed($signatureImage) }}" width="{{ $signatureImageWidth }}" alt="" style="display: block; max-width: 100%; height: auto; border: 0;">
                    </div>
                @endif
            </div>
        @elseif (! $hasSignature)
            <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0 12px;">
            <p style="font-size: 12px; color: #6b7280;">
                {{ $company->name }}@if ($company->phone) · {{ $company->phone }}@endif @if ($company->email) · {{ $company->email }}@endif
                @if ($company->address)<br>{{ $company->address }}@if ($company->city), {{ $company->city }}@endif @endif
            </p>
        @endif
    </div>
</body>
</html>
