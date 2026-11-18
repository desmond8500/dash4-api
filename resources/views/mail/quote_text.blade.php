{!! $body !!}
@if (! $hasSignature)

--
{{ $company->name }}@if ($company->phone) · {{ $company->phone }}@endif @if ($company->email) · {{ $company->email }}@endif
@endif
