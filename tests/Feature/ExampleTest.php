<?php

test('the application redirects to the consumer price panel', function () {
    $response = $this->get('/');

    $response->assertRedirect('/market');
});
