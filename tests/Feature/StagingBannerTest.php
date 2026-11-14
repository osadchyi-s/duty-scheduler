<?php

it('shows the staging banner only in the staging environment', function () {
    $this->get('/admin/login')->assertOk()->assertDontSee('Тестове середовище (staging)');

    app()->detectEnvironment(fn () => 'staging');

    $this->get('/admin/login')->assertOk()->assertSee('Тестове середовище (staging)');
});
