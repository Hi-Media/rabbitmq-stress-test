#!/usr/bin/env bash

scripts_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
output_path="$1" # directory containing logs, in which graphs will be generated
N="$2"           # number of seconds by segment
L="$3"           # number of msg by batch
IS_DURABLE="$4"  # with persistence?

min_ts="$(cat $output_path/msg*csv | cut -d, -f1 | sort 2>/dev/null | head -n1 | xargs -i{} date -d '{}' +%s)"
((min_ts -= 1))
echo "Min timestamp: $min_ts"

max_ts="$(cat $output_path/msg*csv | cut -d, -f1 | sort 2>/dev/null | tail -n1 | xargs -i{} date -d '{}' +%s)"
((max_ts += 1))
echo "Max timestamp: $max_ts"

nb_produced_msgs="$(cat $output_path/msg_produced*csv | wc -l)"
echo "Nb of produced messages (observed / expected): $nb_produced_msgs / $((4*$N*3*$L + 3*$N*5*$L))"

nb_consumed_msgs="$(          cat $output_path/msg_consumed*csv | wc -l)"
nb_consumed_msgs_distinct="$( cat $output_path/msg_consumed*csv | cut -d, -f2 | sort | uniq    | wc -l)"
nb_consumed_msgs_one_time="$( cat $output_path/msg_consumed*csv | cut -d, -f2 | sort | uniq -u | wc -l)"
nb_consumed_msgs_two_times="$(cat $output_path/msg_consumed*csv | cut -d, -f2 | sort | uniq -d | wc -l)"
echo "Nb of consumed messages (distinct / total): $nb_consumed_msgs_distinct / $nb_consumed_msgs"
echo "    – $nb_consumed_msgs_one_time ×1"
echo "    – $nb_consumed_msgs_two_times ×2 (duplicates)"

#if [[ $nb_consumed_msgs_two_times > 0 ]]; then
#    echo "Duplicate consumed messages: {$(cat $output_path/msg_consumed*csv | cut -d, -f2 | sort | uniq -d | tr '\n' ' ')}"
#fi

nb_not_consumed_msgs="$(cat $output_path/msg*csv | cut -d, -f2 | sort | uniq -u | wc -l)"
echo -n "Nb of not consumed messages: $nb_not_consumed_msgs"
if [[ $nb_consumed_msgs_two_times > 0 ]]; then
    echo " {$(cat $output_path/msg*csv | cut -d, -f2 | sort | uniq -u | tr '\n' ' ')}"
else
    echo
fi

for f in $(ls $output_path/msg*csv); do
    f_min_ts="$(cat "$f" | cut -d, -f1 | sort 2>/dev/null | head -n1 | xargs -i{} date -d '{}' +%s)"
    f_max_ts="$(cat "$f" | cut -d, -f1 | sort 2>/dev/null | tail -n1 | xargs -i{} date -d '{}' +%s)"
    ( \
        seq 0 $(($f_min_ts-1-$min_ts)) | sed s/$/,0/
        sort "$f" | cut -d, -f1 | uniq -c | sed 's/:/ /g' | awk -v min_ts="$min_ts" -F' ' '{print mktime(strftime("%Y %m %d ")$2 " " $3 " " $4)-min_ts "," $1}'
        seq $(($f_max_ts+1-$min_ts)) $(($max_ts-$min_ts)) | sed s/$/,0/
    ) > $f.stats.tmp
    awk -F, 'BEGIN {last=-1} {while ($1 > last+1) {print ++last",0"} print $0; last++}' $f.stats.tmp > $f.stats
    rm -f $f.stats.tmp
done
echo "Stats generated."

producer1="$(ls -1 $output_path/msg_produced*.csv.stats | head -n1)"
producer2="$(ls -1 $output_path/msg_produced*.csv.stats | tail -n+2)"
consumer1="$(ls -1 $output_path/msg_consumed*.csv.stats | head -n1)"
consumer2="$(ls -1 $output_path/msg_consumed*.csv.stats | tail -n+2)"
all_stats="$output_path/msg_all.csv.stats"
paste -d, "$producer1" "$producer2" "$consumer1" "$consumer2" | cut -d, -f1,2,4,6,8 \
    | awk -F, 'BEGIN {sum=0} {sum += $2+$3-$4-$5; print $1,$2,$3,$4,$5,(sum >= 0 ? sum : 0)}' > "$all_stats"

xrange_max=$(($max_ts-$min_ts))
graph_path="$output_path/graph_queue_activity.png"
gnuplot \
    -e "csv='$all_stats'" \
    -e "output='$graph_path'" \
    -e "xrangemax='$xrange_max'" \
    -e "N='$N'; L='$L'; IS_DURABLE='$IS_DURABLE'" \
    "$scripts_dir/plot_queue.script"
echo "Queue graph path: $graph_path"

# CPU
# hostname;interval;timestamp;CPU;%user;%nice;%system;%iowait;%steal;%idle => epoch,%user,%nice,%system,%iowait,%steal
for f in $(ls $output_path/sar_cpu_*csv); do
    tail -n +2 $f | awk -F';' -v min_ts="$min_ts" -v max_ts="$max_ts" '{
        split($3, dt, " ")
        split(dt[1], d, "-")
        split(dt[2], t, ":")
        epoch = mktime(d[1] " " d[2] " " d[3] " " t[1] " " t[2] " " t[3])
        if (epoch>=min_ts && epoch<=max_ts) {
            print epoch-min_ts "," $5 "," $6 "," $7 "," $8 "," $9
        }
    }' > $f.stats
done

# Memory
# hostname;interval;timestamp;kbmemfree;kbmemused;%memused;kbbuffers;kbcached;kbcommit;%commit
# => epoch,kbmemfree,kbmemused,%memused,deltaused (compared to first measure)
for f in $(ls $output_path/sar_mem_*csv); do
    tail -n +2 $f | awk -F';' -v min_ts="$min_ts" -v max_ts="$max_ts" 'BEGIN {first_used=-1} {
        split($3, dt, " ")
        split(dt[1], d, "-")
        split(dt[2], t, ":")
        epoch = mktime(d[1] " " d[2] " " d[3] " " t[1] " " t[2] " " t[3])
        if (epoch>=min_ts && epoch<=max_ts) {
            delta_used = (first_used == -1 ? 0 : $5-first_used)
            printf("%d,%0.f,%0.f,%0.f,%0.f\n", epoch-min_ts, $4/1024, $5/1024, $6, delta_used/1024)
            first_used = $5
        }
    }' > $f.stats
done

cpu_a="$output_path/sar_cpu_111.csv.stats"
cpu_b="$output_path/sar_cpu_112.csv.stats"
cpu_local="$output_path/sar_cpu_local.csv.stats"
graph_path="$output_path/graph_cpu_activity.png"
gnuplot \
    -e "cpu_a='$cpu_a'; cpu_b='$cpu_b'; cpu_local='$cpu_local'" \
    -e "output='$graph_path'" \
    -e "xrangemax='$xrange_max'" \
    -e "N='$N'; L='$L'; IS_DURABLE='$IS_DURABLE'" \
    "$scripts_dir/plot_cpu.script"
echo "CPU graph path: $graph_path"

mem_a="$output_path/sar_mem_111.csv.stats"
mem_b="$output_path/sar_mem_112.csv.stats"
mem_local="$output_path/sar_mem_local.csv.stats"
graph_path="$output_path/graph_memory_activity.png"
gnuplot \
    -e "mem_a='$mem_a'; mem_b='$mem_b'; mem_local='$mem_local'" \
    -e "output='$graph_path'" \
    -e "xrangemax='$xrange_max'" \
    -e "N='$N'; L='$L'; IS_DURABLE='$IS_DURABLE'" \
    "$scripts_dir/plot_memory.script"
echo "Memory graph path: $graph_path"
