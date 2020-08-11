## 处理脚本

```
# 提取图片地址

# 下载图片

# 替换图片


# 修改文件名

# 分类下载地址  去重

cat imgsrc | sort -n | uniq | grep -v "imgur" > imgnormal
cat imgsrc | sort -n | uniq | grep "imgur" > imgur

sh imgnormal
sh imgur
```