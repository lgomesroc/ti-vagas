<?php
namespace App\Sources;
use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Illuminate\Support\Facades\Http;

class MicrosoftSource implements VacancySource
{
    private const BASE='https://apply.careers.microsoft.com';
    private readonly VacancyLocationFilter $filter;
    public function __construct(private readonly ?string $caBundle=null,private readonly int $maxJobs=300,?VacancyLocationFilter $locationFilter=null){$this->filter=$locationFilter??new VacancyLocationFilter();}
    public function name(): string{return 'microsoft';}
    public function fetch(): iterable
    {
        $start=0;$processed=0;$total=0;$out=[];$seen=[];
        while($processed<$this->maxJobs){
            $res=Http::acceptJson()->timeout(60)->withHeaders(['User-Agent'=>'TI-Vagas/1.0'])->withOptions(['verify'=>$this->caBundle?:true])->get(self::BASE.'/api/pcsx/search',[
                'domain'=>'microsoft.com','query'=>'','location'=>'Brazil','start'=>$start,'sort_by'=>'timestamp']);
            if(!$res->successful())break;
            $positions=$res->json('data.positions',[]);$total=(int)$res->json('data.count',$total);
            if(!is_array($positions)||!$positions)break;
            foreach($positions as $p){
                if($processed >= $this->maxJobs)break;if(!is_array($p))continue;
                $id=trim((string)($p['id']??''));$title=trim((string)($p['name']??''));
                if(!$id||!$title||isset($seen[$id]))continue;$seen[$id]=true;$processed++;
                $loc=$this->locations($p['standardizedLocations']??$p['locations']??[]);
                if(!$this->junior($title.' '.$loc))continue;
                $detail=Http::acceptJson()->timeout(60)->withHeaders(['User-Agent'=>'TI-Vagas/1.0'])->withOptions(['verify'=>$this->caBundle?:true])->get(self::BASE.'/api/pcsx/position_details',['domain'=>'microsoft.com','position_id'=>$id]);
                if(!$detail->successful())continue;$d=$detail->json('data',[]);
                if(!is_array($d))continue;$desc=$this->clean((string)($d['jobDescription']??''));
                if(!$desc||!$this->junior($title.' '.$desc)||!$this->isTech($title.' '.$desc))continue;
                $model=$this->filter->classifyWorkModel($title,$loc,$desc);
                if(!$this->filter->isEligibleForCollection($loc,$title,$desc,$model))continue;
                $ts=$p['postedTs']??$d['postedTs']??null;if(!is_numeric($ts)||(int)$ts<=0)continue;
                $ts=(int)$ts;if($ts>9999999999)$ts=(int)($ts/1000);
                $url=trim((string)($p['positionUrl']??''));if(!$url)$url=self::BASE.'/careers/job/'.rawurlencode($id);elseif(!filter_var($url,FILTER_VALIDATE_URL))$url=self::BASE.'/'.ltrim($url,'/');
                $out[]=['title'=>$title,'company'=>'Microsoft','description'=>$desc,'location'=>$loc?:null,'work_model'=>$model,
                    'employment_type'=>preg_match('/intern|estagio|estágio/i',$title.' '.$desc)?'Estágio':null,'salary'=>null,
                    'application_url'=>$url,'external_id'=>$id,'original_published_at'=>date(DATE_ATOM,$ts)];
            }
            $start+=count($positions);if(($total&&$start >= $total)||count($positions)<10)break;
        }return $out;
    }
    private function junior(string $s): bool {if(preg_match('/senior|sênior|\bsr\b|staff|principal|lead|manager|director|\biii\b|\biv\b/i',$s))return false;return preg_match('/junior|júnior|entry.level|early.career|intern|estagio|estágio|trainee|graduate|new.grad/i',$s)===1;}
    private function isTech(string $s): bool{return preg_match('/software|developer|desenvolvedor|engineering|engenharia|programmer|programador|cloud|devops|cyber|security|seguranca|data engineer|infraestrutura|infrastructure|sre|qa engineer|technical support|suporte tecnico|technology|tecnologia/i',$s)===1;}
    private function locations(mixed $v): string {if(is_string($v))return trim($v);if(!is_array($v))return ''; $a=[];foreach($v as $x){if(is_string($x))$a[]=trim($x);elseif(is_array($x))$a[]=trim((string)($x['label']??$x['name']??$x['location']??''));}return implode(', ',array_filter(array_unique($a)));}
    private function clean(string $s): string {$s=html_entity_decode($s,ENT_QUOTES|ENT_HTML5,'UTF-8');$s=preg_replace('/<\s*br\s*\/?>|<\/\s*p\s*>|<\/\s*div\s*>|<\/\s*h[1-6]\s*>/i',"\n",$s);$s=strip_tags($s);$s=html_entity_decode($s,ENT_QUOTES|ENT_HTML5,'UTF-8');$s=preg_replace('/[ \t]+/',' ',$s);return trim(preg_replace('/ *\n */',"\n",$s));}
}