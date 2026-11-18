<?php
namespace Tests\Feature;
use App\Sources\IbmSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class IbmSourceTest extends TestCase {
 public function test_it_collects_entry_level_technology_job_with_original_date(): void {
  Http::fake(['www-api.ibm.com/search/api/v2'=>Http::response(['hits'=>['total'=>['value'=>1],'hits'=>[['_source'=>[
   'id'=>'IBM-1','title'=>'Software Developer Entry Level','body'=>'Software development in Brazil','url'=>'https://www.ibm.com/jobs/1',
   'field_keyword_18'=>'Entry Level','field_keyword_19'=>'São Paulo, Brazil','datePosted'=>'2026-11-10'
  ]]]]])]);
  $v=array_values((new IbmSource())->fetch());$this->assertCount(1,$v);$this->assertSame('IBM',$v[0]['company']);$this->assertSame('IBM-1',$v[0]['external_id']);$this->assertNotNull($v[0]['original_published_at']);
 }
 public function test_it_excludes_professional_and_missing_date_records(): void {
  Http::fake(['www-api.ibm.com/search/api/v2'=>Http::response(['hits'=>['total'=>['value'=>2],'hits'=>[
   ['_source'=>['id'=>'1','title'=>'Software Engineer','body'=>'Software','url'=>'https://www.ibm.com/1','field_keyword_18'=>'Professional','field_keyword_19'=>'Brazil','datePosted'=>'2026-11-10']],
   ['_source'=>['id'=>'2','title'=>'Software Developer Entry Level','body'=>'Software development Brazil','url'=>'https://www.ibm.com/2','field_keyword_18'=>'Entry Level','field_keyword_19'=>'Brazil']]
  ]]])]);
  $this->assertCount(0,(new IbmSource())->fetch());
 }
}