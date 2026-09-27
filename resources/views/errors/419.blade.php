@extends('errors.layout')

@section('code', '419')
@section('title', 'Seite abgelaufen')
@section('message', 'Die Seite war zu lange geöffnet, deshalb gilt das Formular nicht mehr. Lade die Seite neu und versuche es noch einmal.')
@section('link_href', url()->previous(url('/admin')))
@section('link_label', 'Seite neu laden')
