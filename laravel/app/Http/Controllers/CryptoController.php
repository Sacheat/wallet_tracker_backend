<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCryptoRequest;
use App\Http\Requests\UpdateCryptoRequest;
use App\Models\Crypto;

class CryptoController extends Controller
{
    public function index()
    {
        return Crypto::all();
    }

    public function store(StoreCryptoRequest $request)
    {
        return Crypto::create($request->validated());
    }

    public function show(Crypto $crypto)
    {
        return $crypto;
    }

    public function update(UpdateCryptoRequest $request, Crypto $crypto)
    {
        $crypto->update($request->validated());

        return $crypto;
    }

    public function destroy(Crypto $crypto)
    {
        $crypto->delete();

        return response()->noContent();
    }
}
