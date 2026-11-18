<?php
namespace App\Sources;
use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class IbmSource implements VacancySource
{
    private const URL = 'https://www-api.ibm.com/search/api/v2';
    private readonly VacancyLocationFilter $filter;
    public function __construct(private readonly ?string $caBundle = null, private readonly int $maxJobs = 500, ?VacancyLocationFilter $locationFilter = null) { $this->filter = $locationFilter ?? new VacancyLocationFilter(); }
    public function name(): string { return 'ibm'; }
    public function fetch(): iterable
    {
        $from=0; $processed=0; $total=null; $out=[];
        while ($processed < $this->maxJobs) {
            $res=Http::acceptJson()->asJson()->timeout(60)->withHeaders(['User-Agent'=>'TI-Vagas/1.0'])->withOptions(['verify'=>$this->caBundle ?: true])->post(self::URL,[
                'appId'=>'careers','scopes'=>['careers2'],'sm'=>['query'=>'','lang'=>'zz'],
                'size'=>min(100,$this->maxJobs-$processed),'from'=>$from,
            ]);
            $res->throw(); $data=$res->json();
            if (!is_array($data)) throw new RuntimeException('Resposta inválida da API de carreiras da IBM.');
            $hits=$data['hits']['hits'] ?? []; $total=(int)($data['hits']['total']['value'] ?? $total ?? 0);
            if (!is_array($hits) || !$hits) break;
            foreach ($hits as $hit) {
                if ($processed >= $this->maxJobs) break;
                $processed++; $job=$hit['_source'] ?? null; if (!is_array($job)) continue;
                $level=strtolower(trim((string)($job['field_keyword_18'] ?? '')));
                if (!in_array($level,['entry level','internship'],true)) continue;
                $title=trim((string)($job['title'] ?? ''));
                $desc=$this->clean((string)($job['body'] ?? $job['description'] ?? ''));
                if (!$title || !$desc || !$this->isTech($title.' '.$desc)) continue;
                $loc=trim((string)($job['field_keyword_19'] ?? ''));
                $model=$this->filter->classifyWorkModel($title,$loc,$desc);
                if (!$this->filter->isEligibleForCollection($loc,$title,$desc,$model)) continue;
                $date=$this->date($job);
                // Não inventa data: registros sem data original não atendem ao contrato do coletor.
                if (!$date) continue;
                $url=trim((string)($job['url'] ?? '')); if (!$url) continue;
                if (!filter_var($url,FILTER_VALIDATE_URL)) $url='https://www.ibm.com/'.ltrim($url,'/');
                $out[]=['title'=>$title,'company'=>'IBM','description'=>$desc,'location'=>$loc ?: null,'work_model'=>$model,
                    'employment_type'=>$level==='internship'?'Estágio':null,'salary'=>null,'application_url'=>$url,
                    'external_id'=>(string)($job['id'] ?? $job['job_id'] ?? $url),'original_published_at'=>$date];
            }
            $from+=count($hits); if (($total && $from >= $total) || count($hits)<100) break;
        }
        return $out;
    }
    private function date(array $j): ?string {
        foreach (['datePosted','postedDate','posted_date','field_date_1','date'] as $k) {
            $v=$j[$k] ?? null;
            if (is_numeric($v)) { $t=(int)$v; if($t>9999999999)$t=(int)($t/1000); if($t>0)return date(DATE_ATOM,$t); }
            if(is_string($v)&&trim($v)!==''){ $t=strtotime($v); if($t!==false)return date(DATE_ATOM,$t); }
        } return null;
    }
    private function isTech(string $s): bool { return preg_match('/software|developer|desenvolvedor|desenvolvimento|programador|programacao|technology|tecnologia|cloud|devops|cyber|security|seguranca|data engineer|engenheiro de dados|infrastructure|infraestrutura|sre|qa engineer|technical support|suporte tecnico/i',$s)===1; }
    private function clean(string $s): string { $s=html_entity_decode($s,ENT_QUOTES|ENT_HTML5,'UTF-8'); $s=preg_replace('/<\s*br\s*\/?>|<\/\s*p\s*>|<\/\s*div\s*>|<\/\s*h[1-6]\s*>/i',"\n",$s); $s=strip_tags($s); $s=html_entity_decode($s,ENT_QUOTES|ENT_HTML5,'UTF-8'); $s=preg_replace('/[ \t]+/',' ',$s); return trim(preg_replace('/ *\n */',"\n",$s)); }
}