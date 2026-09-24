<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;
class UpdateProductRequest extends FormRequest { public function authorize(): bool { return true; } public function rules(): array { return ['name'=>['required','string','max:500'],'category'=>['required','string','max:100'],'subcategory'=>['required','string','max:100'],'presentation'=>['required','string','max:100'],'price'=>['required','integer','min:0'],'inventory'=>['required','integer','min:0'],'expected_revision'=>['required','integer','min:1'],'image'=>['nullable',File::image()->types(['jpeg','png','webp'])->max('5mb')]]; } }
