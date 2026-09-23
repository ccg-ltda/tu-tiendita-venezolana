<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class UpdateProductStatusRequest extends FormRequest { public function authorize(): bool { return true; } public function rules(): array { return ['active'=>['required','boolean'],'expected_revision'=>['required','integer','min:1']]; } }
