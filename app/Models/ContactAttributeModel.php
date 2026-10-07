<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

class ContactAttributeModel extends Model
{
    use SelfHealingSchema;

    protected $table          = 'contact_attributes';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields  = ['attr_key', 'label', 'type', 'options', 'default_value'];
    protected $useTimestamps  = true;
    protected $dateFormat     = 'datetime';
}
