<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class EmailDripStepModel extends Model
{
    protected $table            = 'email_drip_steps';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'drip_id',
        'step_order',
        'delay_hours',
        'subject',
        'html_content',
        'builder_id',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}
