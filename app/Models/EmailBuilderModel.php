<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class EmailBuilderModel extends Model
{
    protected $table            = 'email_builders';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'name',
        'subject',
        'html_content',
        'cheerio_builder_id',
        'status',
        'created_by',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'name'   => 'required|max_length[191]',
        'status' => 'permit_empty|in_list[draft,active,archived]',
    ];
}
