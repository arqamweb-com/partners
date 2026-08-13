<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * لارافيل 11+ لم يعد يضع هذا الـ trait افتراضيًا. وجوده هنا هو ما
     * يجعل $this->authorize(...) في كل Controller يرمي 403 تلقائيًا —
     * أي مسار ينسى السطر ده يفشل ظاهرًا، لا يمرّ صامتًا.
     */
    use AuthorizesRequests;
}
