<?php
// 指定文件夹 提取图片地址 替换图片地址  文件更名

class Worker
{
    // 获取文件
    public function getFiles(array $dirArr): array
    {
        $res = [];
        foreach ($dirArr as $dir) {
            // 查出文件夹下所有文件 glob
            $arr = glob("./" . $dir . "/*.md");
            // var_dump($arr);
            $res = array_merge($res, $arr);
        }
        // 返回数组
        return $res;
    }
    // 获取内容
    // 重写到新文件
    public function getContents(string $file)
    {
        // 逐行读取文件内容
        $handle = @fopen($file, "r");
        $dirs = explode("/", $file);
        $filename = array_pop($dirs);
        $filename = explode(".", $filename)[0];

        $filepath = substr($filename, 0, 7);

        $title = "";
        $str = "";
        $img = "";

        $ntitle = '';

        if ($handle) {
            while (($buffer = fgets($handle, 4096)) !== false) {
                // 提取标题
                // if (preg_match("## ",$buffer)) {
                if (strpos($buffer, "## ") !== false && $title == "") {
                    $title = trim(explode("## ", $buffer)[1], "\r\n");
                    // echo $title, "\n";
                    array_push($dirs, $title);
                    $nfile = implode("/", $dirs);
                    // echo $nfile . ".md\n";
                    $ntitle = "./" . $filepath . "/" . $filename . "_" . $title . ".md";
                    // $handle1 = @fopen($ntitle, "a");
                    $str .= $buffer;
                } else {
                    // 处理图片
                    if (strpos($buffer, "]: http") != false && (strpos($buffer, "jpg") != false || strpos($buffer, "png") != false)) {
                            // echo $buffer;
                            $imgstr = trim($buffer, "\r\n");
                            $imgarr = explode("/", $imgstr);

                            $oldsrc = explode("]: ", $imgstr)[1];
                            $alen = count($imgarr);
                            if($alen == 4){
                                $newsrc = array_pop($imgarr);
                            }else{
                                $newsrc = $imgarr[$alen - 3] . '/' . $imgarr[$alen - 2] . '/' . $imgarr[$alen - 1];
                            }
                            $newsrc = "./img/" . $newsrc;
                            echo $newsrc,"\n";
                            file_put_contents("./imgsrc", "curl -o " . $newsrc . " --create-dirs " . $oldsrc . "\n", FILE_APPEND);
                            $newbuffer = str_replace($oldsrc, $newsrc, $buffer);
                            $str .= $newbuffer;
                    }else{
                        $str .= $buffer;
                    }
                }
            }
            // if (!feof($handle)) {
            // }
            fclose($handle);
            // fclose($handle1);
            // file_put_contents($ntitle, $str); // 写入新文件
            // file_put_contents($ntitle, $str, FILE_APPEND);
        }
        // 匹配图片
        // 图片地址写入文件 或 数据库
        // 替换掉原地址
        // 关闭文件
        // 重命名
    }
    // 匹配图片 处理 替换
    public function dealPics(string $content)
    {
        // 匹配图片
        //
    }
    // 重命名文件
    public function rename(string $file, string $title)
    {
        // 重命名文件
    }
    public function deal(array $dirArr)
    {
        $files = $this->getFiles($dirArr);
        foreach ($files as $file) {
            $content = $this->getContents($file);
        }
    }

    public function queryAndCreate(string $oldsrc, string $newsrc)
    {
        $dbh = new SQLite3('./data.db');

        // $dbh->exec('
        //   CREATE table img (oldsrc varchar(255),newsrc varchar(255));
        // ');

        $stmt = $dbh->prepare('SELECT * from img where newsrc = "' . $newsrc . '";');
        $result = $stmt->execute();

        if ($row = $result->fetchArray()) {
            // return false;
        }else{
            $stmt = $dbh->prepare('insert into img (oldsrc,newsrc) values("' . $oldsrc . '","' . $newsrc . '");');
            $result = $stmt->execute();
        }

        // $dbh->exec('
        //   DROP table people;
        // ');

    }

    public function query(string $oldsrc, string $newsrc)
    {
        $dbh = new SQLite3('./data.db');

        $stmt = $dbh->prepare('SELECT * from img where newsrc = "' . $newsrc . '";');
        $result = $stmt->execute();

        while ($row = $result->fetchArray()) {
            echo $row;
        }


    }
}

$dirArr = ["2020-06"];
$worker = new Worker();
$files = $worker->getFiles($dirArr);
foreach ($files as $val) {
    $worker->getContents($val);
}

// $worker->queryAndCreate();
