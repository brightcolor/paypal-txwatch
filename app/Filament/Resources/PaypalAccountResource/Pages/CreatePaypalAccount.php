<?php

namespace App\Filament\Resources\PaypalAccountResource\Pages;

use App\Filament\Resources\PaypalAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreatePaypalAccount extends CreateRecord
{
    protected static string $resource = PaypalAccountResource::class;
}
