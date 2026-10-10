<?php

test('redirects the application root to login for guests', function () {
    $response = $this->get(route('home'));

    $response->assertRedirectToRoute('login');
});
