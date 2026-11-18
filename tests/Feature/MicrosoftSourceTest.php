<?php
namespace Tests\Feature;
use App\Sources\MicrosoftSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class MicrosoftSourceTest extends TestCase {
 public function test_it_collects_brazilian_early_career_technology_job(): void {
  Http::fake([
   'apply.careers.microsoft.com/api/pcsx/search*'=>Http::response(['data'=>['count'=>1,'positions'=>[[
    'id'=>'12345','name'=>'Software Engineer - Early Career','positionUrl'=>'/careers/job/12345','postedTs'=>1794268800,'standardizedLocations'=>['São Paulo, Brazil']
   ]]]]),
   'apply.careers.microsoft.com/api/pcsx/position_details*'=>Http::response(['data'=>['jobDescription'=>'<p>Build software products for customers in Brazil.</p>']])
  ]);
  $v=array_values((new MicrosoftSource())->fetch());$this->assertCount(1,$v);$this->assertSame('Microsoft',$v[0]['company']);$this->assertSame('12345',$v[0]['external_id']);$this->assertNotNull($v[0]['original_published_at']);
 }
 public function test_it_skips_senior_roles_without_requesting_details(): void {
  Http::fake(['apply.careers.microsoft.com/api/pcsx/search*'=>Http::response(['data'=>['count'=>1,'positions'=>[[
   'id'=>'999','name'=>'Senior Software Engineer','postedTs'=>1794268800,'standardizedLocations'=>['Brazil']
  ]]]])]);
  $this->assertCount(0,(new MicrosoftSource())->fetch());Http::assertNotSent(fn($r)=>str_contains($r->url(),'position_details'));
 }
}