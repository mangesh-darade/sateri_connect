<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class ContactAttributeModel extends Model
{
    protected $table          = 'contact_attributes';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields  = ['attr_key', 'label', 'type', 'options', 'default_value'];
    protected $useTimestamps  = true;
    protected $dateFormat     = 'datetime';
}
