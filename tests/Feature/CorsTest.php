<?php

it('laisse l\'application mobile installée appeler l\'API', function (string $origin) {
    $this->withHeaders(['Origin' => $origin, 'Access-Control-Request-Method' => 'POST'])
        ->options('/api/v1/auth/login')
        ->assertHeader('Access-Control-Allow-Origin', $origin);
})->with(['https://localhost', 'capacitor://localhost']);
