<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\SelfHealingSchema;
use CodeIgniter\Model;

class QuickReplyModel extends Model
{
    use SelfHealingSchema;

    protected $table          = 'quick_replies';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields  = ['shortcut', 'title', 'message', 'created_by'];
    protected $useTimestamps  = true;
    protected $dateFormat     = 'datetime';
}
