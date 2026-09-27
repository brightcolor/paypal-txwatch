@extends('errors.layout')

@php
    $headers = isset($exception) && method_exists($exception, 'getHeaders') ? $exception->getHeaders() : [];
    $wait = (int) ($headers['Retry-After'] ?? 0);
@endphp

@section('code', '429')
@section('title', 'Zu viele Versuche')
@section('message', $wait > 0
    ? "In kurzer Zeit kamen zu viele Anfragen. Versuche es in {$wait} Sekunden erneut."
    : 'In kurzer Zeit kamen zu viele Anfragen. Warte kurz und versuche es dann erneut.')
@section('link_href', url()->previous(url('/admin')))
@section('link_label', 'Zurück')
