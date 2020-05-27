<?php

require_once ('jpgraph-4.3.1/src/jpgraph.php');
require_once ('jpgraph-4.3.1/src/jpgraph_line.php');
require_once ('jpgraph-4.3.1/src/jpgraph_date.php');

class Charger
{
    protected $multipliers = [
        'inbound_calls' => 0,
        'outbound_calls' => 0,
        'sms' => 0,
        'internet_init' => 0,
        'internet' => 3
    ];

    protected $free_sms = 10;
    protected $init_internet = 0; // first N megabytes where init multiplier can be used

    protected $quantity = [
        'inbound_calls' => 0,
        'outbound_calls' => 0,
        'sms' => 0,
        'internet' => 0  //megabytes
    ];

    public function add($type, $q)
    {
        $this->quantity[$type] += $q;
    }

    public function get($type)
    {
        return $this->quantity[$type];
    }

    public function calculate($type)
    {
        $q = $this->quantity[$type];

        if($type == 'sms')
        {
            $q = ($q - $this->free_sms <= 0) ? '0' : $q - $this->free_sms;
        }

        if($type == 'internet')
        {
            $main_q = ($q - $this->init_internet <= 0) ? 0 : $q - $this->init_internet;
            $init_q = ($q > $this->init_internet) ? $this->init_internet : $q;
            return $this->multipliers['internet_init'] * $init_q + $this->multipliers['internet'] * $main_q;
            
        }

        return $this->multipliers[$type] * $q;
    }

    public function calculate_all()
    {
        $sum = 0;
        foreach($this->quantity as $type=>$quantity)
        {
            $sum += $this->calculate($type);
        }
        return $sum;
    }

    public function convertInternetToMB()
    {
        $this->quantity['internet'] = $this->quantity['internet'] / (1024 * 1024);
    }
}

if(!isset($argv[1]) || !isset($argv[2]))
{
    die("Usage: php netflow.php netflow_data_file customer_ip");
}

$ip = $argv[2];
$netflow_data_file = $argv[1];
$netflow_data = array_map("trim", explode(PHP_EOL, shell_exec('nfdump -r '.$netflow_data_file.' -o "fmt: %ts %sa %ibyt" | sed "s/  */ /g"'))); 

$netflow_calc = new Charger();
$graph_data = [];

foreach($netflow_data as $netflow_row)
{
    if($netflow_row == '')
        continue; // last row is empty
    $exploded_row = explode(' ', $netflow_row);
    $processed_row = [
        'timestamp' => $exploded_row[0].' '.$exploded_row[1],
        'ip' => $exploded_row[2],
        'bytes_sent' => $exploded_row[3]
    ];

    if($processed_row['ip'] == $ip)
    {
        $unix_timestamp = strtotime($processed_row['timestamp']);
        $netflow_calc->add('internet', $processed_row['bytes_sent']);

        $graph_data[$unix_timestamp] = (array_key_exists($unix_timestamp, $graph_data)) ? ($graph_data[$unix_timestamp] + $processed_row['bytes_sent']) : (int) $processed_row['bytes_sent'];
    }
}

$netflow_calc->convertInternetToMB();
ksort($graph_data);
// convert bytes to kb for graph
foreach($graph_data as $time=>$data)
{
    $graph_data[$time] = $data / 1024;
}

echo 'Internet: '.$netflow_calc->get('internet').'MB, cost: '.$netflow_calc->calculate('internet').PHP_EOL;

$graph = new Graph(1000,600);
 
$graph->SetMargin(100,40,30,130);
 
$graph->SetScale('datlin',0,max(array_values($graph_data))+50);
$graph->title->Set("Traffic usage (kb)");
 
$graph->xaxis->SetLabelAngle(90);
 
$line = new LinePlot(array_values($graph_data), array_keys($graph_data));
$line->SetLegend('Timestamp');
$line->SetFillColor('lightblue@0.5');
$graph->Add($line);

$gdImgHandler = $graph->Stroke(_IMG_HANDLER);
 
$filename = "/tmp/".$ip."_".time().".png";
$graph->img->Stream($filename);

echo "Graph saved to ".$filename.PHP_EOL;

