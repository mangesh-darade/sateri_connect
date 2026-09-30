<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class QuickReplyModel extends Model
{
    protected $table          = 'quick_replies';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields  = ['shortcut', 'title', 'message', 'created_by'];
    protected $useTimestamps  = true;
    protected $dateFormat     = 'datetime';
}
