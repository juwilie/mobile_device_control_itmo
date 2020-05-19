<?php

function parse_csv($filename)
{
    $header = NULL;
    $delimiter=',';
    $data = array();
    if (($handle = fopen($filename, 'r')) !== FALSE)
    {
        while (($row = fgetcsv($handle, 1000, $delimiter)) !== FALSE)
        {
            if(!$header)
                $header = $row;
            else
                $data[] = array_combine($header, $row);
        }
        fclose($handle);
    }
    return $data;
}

class Charger
{
    protected $multipliers = [
        'inbound_calls' => 0,
        'outbound_calls' => 2,
        'sms' => 1,
        'internet' => 0 
    ];

    protected $free_sms = 10;

    protected $quantity = [
        'inbound_calls' => 0,
        'outbound_calls' => 0,
        'sms' => 0,
        'internet' => 0 
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

}

if(!isset($argv[1]) || !isset($argv[2]))
{
    die("Usage: php cdr.php cdr_data_file phone_number");
}

$cdr_data = parse_csv($argv[1]); 
$phone_number = $argv[2];

$cdr_calc = new Charger();

foreach($cdr_data as $cdr_row)
{
    if($cdr_row['msisdn_origin'] == $phone_number)
    {
        $cdr_calc->add('sms', $cdr_row['sms_number']);
        $cdr_calc->add('outbound_calls', $cdr_row['call_duration']);
    }
    if($cdr_row['msisdn_dest'] == $phone_number)
    {
        $cdr_calc->add('inbound_calls', $cdr_row['call_duration']);
    }
}

echo 'Inbound calls time: '.$cdr_calc->get('inbound_calls').', cost: '.$cdr_calc->calculate('inbound_calls').PHP_EOL;
echo 'Outbound calls time: '.$cdr_calc->get('outbound_calls').', cost: '.$cdr_calc->calculate('outbound_calls').PHP_EOL;
echo 'SMS count: '.$cdr_calc->get('sms').', cost: '.$cdr_calc->calculate('sms').PHP_EOL;
echo 'Total cost: '.$cdr_calc->calculate_all().PHP_EOL;