<?php

test('the application redirects to the protected workspace', function () {
    $response = $this->get('/');

    $response->assertRedirect('/dashboard');
});
