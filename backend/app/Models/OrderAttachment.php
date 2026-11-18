<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderAttachment extends Model
{
    protected $connection = 'mysql';
    public $timestamps = false;
    protected $fillable = ['order_id', 'name', 'mime', 'data_base64', 'uploaded_by_admin_id'];
    protected $hidden = ['data_base64'];
}
